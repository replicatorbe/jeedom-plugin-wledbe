<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

/*
 * Pilotage local des contrôleurs WLED, bandes et matrices.
 *
 * Tout passe par l'API JSON de WLED (/json/*), en HTTP sur le réseau local,
 * sans broker ni cloud. Deux choix structurent la classe :
 *
 *   - Chaque ordre est vérifié. WLED tourne souvent sur un ESP8266 au Wi-Fi
 *     fragile : un ordre perdu ne se voit pas, la lampe reste simplement dans
 *     l'ancien état. On envoie donc avec « v »:true, qui fait renvoyer l'état
 *     réellement appliqué dans la même réponse, on le compare à ce qui a été
 *     demandé (mismatches()), et on relance si besoin.
 *
 *   - Les effets et palettes sont retenus par leur nom. Leur numéro change
 *     d'une version de WLED à l'autre ; les listes sont relues sur l'appareil
 *     à chaque changement de version.
 *
 * L'identifiant logique d'un équipement est l'adresse MAC : une adresse IP qui
 * change (DHCP) est retrouvée par la découverte sans recréer l'équipement.
 */
class wledbe extends eqLogic {

    /* ============================================================= RÉGLAGES */

    const DEFAULT_TIMEOUT = 3;

    /* Essais d'un ordre avant de le déclarer perdu. */
    const DEFAULT_TRIES = 3;

    /* Temps laissé à WLED pour appliquer un ordre différé (un preset s'applique
     * au tour de boucle suivant, pas dans la réponse) avant de relire l'état. */
    const SETTLE_MS = 400;

    /* Temps que cron() s'autorise pour relever tous les appareils. */
    const CRON_BUDGET = 40;

    /* Un appareil injoignable depuis ce nombre de relevés n'est plus relevé
     * qu'une minute sur cinq : son délai d'attente retarderait les autres. */
    const OFFLINE_AFTER = 3;

    /* Durée d'écoute du mDNS lors d'une recherche. */
    const MDNS_SECONDS = 5;

    /* Clés d'un ordre qui ne décrivent pas un état : rien à vérifier. */
    const UNVERIFIED_KEYS = array('v', 'transition', 'tt', 'tb', 'time', 'psave', 'pdel', 'rb', 'lor',
                                  'np', 'ib', 'sb', 'o', 'rmcpal', 'playlist', 'pl');

    /* ================================================================ CRON */

    /* Relevé de l'état de chaque appareil, une fois par minute. */
    public static function cron() {
        $deadline = microtime(true) + self::CRON_BUDGET;
        $slowTurn = ((int) date('i')) % 5 === 0;
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if (microtime(true) > $deadline) {
                break;
            }
            if (!$eqLogic->isConfigured()) {
                continue;
            }
            if ((int) $eqLogic->getCache('failures', 0) >= self::OFFLINE_AFTER && !$slowTurn) {
                continue;
            }
            try {
                $eqLogic->pollNow();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /*
     * Une fois par heure : relecture des listes (un preset ajouté dans
     * l'interface de WLED ne change pas la version), puis mDNS pour retrouver
     * un appareil qui a changé d'adresse et signaler les nouveaux.
     */
    public static function cronHourly() {
        $lost = false;
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if (!$eqLogic->isConfigured()) {
                continue;
            }
            if ((int) $eqLogic->getCache('failures', 0) >= self::OFFLINE_AFTER) {
                $lost = true;
                continue;
            }
            try {
                $eqLogic->refreshLists();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
        if ($lost || config::byKey('auto_discover', __CLASS__, 1) == 1) {
            try {
                self::backgroundDiscovery();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', __('Découverte automatique :', __FILE__) . ' ' . $e->getMessage());
            }
        }
    }

    /* ======================================================== CYCLE DE VIE */

    /* Aucune exception ici : le coeur crée l'équipement avec son seul nom. */
    public function preSave() {
        if ($this->getId() == '') {
            $this->setIsEnable(1);
            $this->setIsVisible(1);
        }
        if ($this->getConfiguration('verify', '') === '') {
            $this->setConfiguration('verify', 1);
        }
        $this->setConfiguration('ip', trim((string) $this->getConfiguration('ip', '')));
        $mac = self::normalizeMac($this->getConfiguration('mac', ''));
        if ($mac !== '') {
            $this->setConfiguration('mac', $mac);
            $this->setLogicalId($mac);
        }
    }

    public function postSave() {
        $this->createCommands();
        if ($this->getCache('ip_seen', '') !== $this->getConfiguration('ip')) {
            $this->setCache('ip_seen', $this->getConfiguration('ip'));
            $this->setCache('failures', 0);
            $this->setCache('lists_sig', '');
        }
    }

    public function isConfigured() {
        return trim((string) $this->getConfiguration('ip', '')) !== '';
    }

    public function isMatrix() {
        return $this->getConfiguration('layout', 'strip') === 'matrix';
    }

    /* ======================================================== HTTP */

    public static function timeout() {
        $timeout = (int) config::byKey('api_timeout', __CLASS__, self::DEFAULT_TIMEOUT);
        return max(1, min(15, $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT));
    }

    public static function tries() {
        $tries = (int) config::byKey('tries', __CLASS__, self::DEFAULT_TRIES);
        return max(1, min(6, $tries > 0 ? $tries : self::DEFAULT_TRIES));
    }

    public static function request($_method, $_url, $_body = null) {
        $ch = curl_init($_url);
        if ($ch === false) {
            throw new Exception(__('Impossible d\'initialiser la requête HTTP.', __FILE__));
        }
        $timeout = self::timeout();
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, min(3, $timeout)),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY          => '',
            CURLOPT_CUSTOMREQUEST  => $_method,
        );
        if ($_body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($_body);
            $opts[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $error !== '') {
            throw new Exception(sprintf(__('WLED injoignable (%s) : %s', __FILE__), $_url, $error));
        }
        return array('code' => $code, 'body' => (string) $body);
    }

    /* Appel vers l'appareil de cet équipement ; rend le JSON décodé. */
    public function call($_method, $_path, $_body = null) {
        if (!$this->isConfigured()) {
            throw new Exception(__('Aucune adresse IP n\'est renseignée.', __FILE__));
        }
        $answer = self::request($_method, 'http://' . $this->getConfiguration('ip') . $_path, $_body);
        if ($answer['code'] < 200 || $answer['code'] >= 300) {
            throw new Exception(sprintf(__('WLED a répondu %s', __FILE__), $answer['code']));
        }
        $data = json_decode($answer['body'], true);
        if (!is_array($data)) {
            throw new Exception(__('Réponse de WLED illisible.', __FILE__));
        }
        return $data;
    }

    /*
     * Lectures en parallèle, pour la découverte : clé => URL en entrée, clé =>
     * corps de la réponse (ou null) en sortie.
     */
    private static function multiGet($_urls, $_connectMs, $_totalMs) {
        if (empty($_urls)) {
            return array();
        }
        $multi = curl_multi_init();
        $handles = array();
        foreach ($_urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_CONNECTTIMEOUT_MS => $_connectMs,
                CURLOPT_TIMEOUT_MS        => $_totalMs,
                CURLOPT_PROXY             => '',
            ));
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 0.2);
            }
        } while ($active && $status == CURLM_OK);

        $out = array();
        foreach ($handles as $key => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = curl_multi_getcontent($ch);
            $out[$key] = ($code === 200 && is_string($body)) ? $body : null;
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $out;
    }

    /* ================================================ IDENTIFICATION */

    public static function normalizeMac($_mac) {
        $mac = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $_mac));
        return strlen($mac) === 12 ? $mac : '';
    }

    /* Réponse de /json/info : est-ce bien un WLED ? */
    public static function looksLikeWled($_info) {
        return is_array($_info) && isset($_info['ver'], $_info['leds'], $_info['mac'])
            && is_array($_info['leds']);
    }

    /* Ce que le plugin retient d'un /json/info. */
    public static function describe($_info, $_ip) {
        $leds = isset($_info['leds']) && is_array($_info['leds']) ? $_info['leds'] : array();
        $matrix = isset($leds['matrix']) && is_array($leds['matrix']) ? $leds['matrix'] : null;
        return array(
            'ip'       => (string) $_ip,
            'mac'      => self::normalizeMac(isset($_info['mac']) ? $_info['mac'] : ''),
            'name'     => isset($_info['name']) ? trim((string) $_info['name']) : '',
            'version'  => isset($_info['ver']) ? (string) $_info['ver'] : '',
            'arch'     => isset($_info['arch']) ? (string) $_info['arch'] : '',
            'leds'     => isset($leds['count']) ? (int) $leds['count'] : 0,
            'rgbw'     => !empty($leds['rgbw']) ? 1 : 0,
            'layout'   => $matrix !== null ? 'matrix' : 'strip',
            'matrix_w' => $matrix !== null && isset($matrix['w']) ? (int) $matrix['w'] : 0,
            'matrix_h' => $matrix !== null && isset($matrix['h']) ? (int) $matrix['h'] : 0,
        );
    }

    public static function probe($_ip) {
        $ip = trim((string) $_ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new Exception(__('Adresse IP invalide.', __FILE__));
        }
        $answer = self::request('GET', 'http://' . $ip . '/json/info');
        $info = json_decode($answer['body'], true);
        if ($answer['code'] !== 200 || !self::looksLikeWled($info)) {
            throw new Exception(sprintf(__('Aucun WLED ne répond à l\'adresse %s.', __FILE__), $ip));
        }
        return self::describe($info, $ip);
    }

    /* ==================================================== DÉCOUVERTE */

    /*
     * Lecture de la sortie de « avahi-browse -rtp _wled._tcp ». Seules les
     * lignes résolues (« = ») portent l'adresse ; WLED publie sa MAC dans le
     * TXT, ce qui permet de reconnaître un appareil sans l'interroger.
     */
    public static function parseAvahi($_lines) {
        $found = array();
        foreach ($_lines as $line) {
            $fields = explode(';', trim((string) $line));
            if (count($fields) < 9 || $fields[0] !== '=' || $fields[2] !== 'IPv4') {
                continue;
            }
            $ip = $fields[7];
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            $mac = '';
            if (isset($fields[9]) && preg_match('/mac=([0-9a-fA-F:]{12,17})/', $fields[9], $m)) {
                $mac = self::normalizeMac($m[1]);
            }
            $found[$ip] = array('ip' => $ip, 'host' => $fields[6], 'mac' => $mac);
        }
        return array_values($found);
    }

    /* Rend null si avahi-browse n'est pas installé (Docker, installation
     * minimale) : la recherche se rabat alors sur le seul balayage HTTP. */
    public static function mdnsBrowse() {
        $out = array();
        $rc = 0;
        exec('command -v avahi-browse 2>/dev/null', $out, $rc);
        if ($rc !== 0 || empty($out)) {
            return null;
        }
        $out = array();
        exec('timeout ' . (int) self::MDNS_SECONDS . ' avahi-browse -rtpk _wled._tcp 2>/dev/null', $out);
        return self::parseAvahi($out);
    }

    private static function localIps() {
        $ips = array();
        $out = array();
        @exec('hostname -I 2>/dev/null', $out);
        foreach (preg_split('/\s+/', trim(implode(' ', $out))) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($ip, '127.') !== 0
                && strpos($ip, '172.17.') !== 0) {
                $ips[] = $ip;
            }
        }
        if (empty($ips)) {
            $internal = parse_url(network::getNetworkAccess('internal'), PHP_URL_HOST);
            if (filter_var($internal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = $internal;
            }
        }
        return $ips;
    }

    /*
     * Recherche des WLED du réseau, sans rien créer. Deux voies, fusionnées par
     * adresse MAC :
     *   - le mDNS (_wled._tcp), immédiat, mais que ni Docker ni les VLAN ne
     *     laissent passer ;
     *   - un balayage HTTP de /json/info sur tout le sous-réseau, en parallèle,
     *     qui passe partout où une requête HTTP passe.
     */
    public static function discover($_subnet = '') {
        $prefixes = array();
        $subnet = trim((string) $_subnet);
        if ($subnet !== '') {
            if (!preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3})(\.\d{1,3}(\/\d+)?)?$/', $subnet, $m)) {
                throw new Exception(__('Sous-réseau illisible : saisissez par exemple 192.168.1.0/24.', __FILE__));
            }
            $prefixes[] = $m[1];
        } else {
            foreach (self::localIps() as $ip) {
                $prefixes[] = substr($ip, 0, strrpos($ip, '.'));
            }
        }
        $prefixes = array_values(array_unique($prefixes));

        $sources = array();
        $mdns = self::mdnsBrowse();
        foreach ((array) $mdns as $entry) {
            $sources[$entry['ip']] = 'mDNS';
        }
        $self = self::localIps();
        foreach ($prefixes as $prefix) {
            for ($i = 1; $i <= 254; $i++) {
                $ip = $prefix . '.' . $i;
                if (!isset($sources[$ip]) && !in_array($ip, $self, true)) {
                    $sources[$ip] = 'HTTP';
                }
            }
        }
        if (empty($sources)) {
            throw new Exception(__('Impossible de déterminer le réseau local : précisez un sous-réseau.', __FILE__));
        }

        /* Les appareils annoncés en mDNS sont interrogés à part, avec un délai
         * plus long : noyé dans les 254 requêtes du balayage, un ESP8266 au
         * Wi-Fi faible répond parfois trop tard et disparaît de la liste. */
        $announced = array();
        $swept = array();
        foreach ($sources as $ip => $source) {
            if ($source === 'mDNS') {
                $announced[$ip] = 'http://' . $ip . '/json/info';
            } else {
                $swept[$ip] = 'http://' . $ip . '/json/info';
            }
        }
        $bodies = self::multiGet($announced, 3000, 6000) + self::multiGet($swept, 1500, 3000);
        $found = array();
        foreach ($bodies as $ip => $body) {
            $info = $body === null ? null : json_decode($body, true);
            if (!self::looksLikeWled($info)) {
                continue;
            }
            $device = self::describe($info, $ip);
            $device['source'] = $sources[$ip];
            $existing = $device['mac'] !== '' ? self::byLogicalId($device['mac'], __CLASS__) : null;
            $device['known'] = is_object($existing) ? $existing->getHumanName() : '';
            $device['known_ip'] = is_object($existing) ? (string) $existing->getConfiguration('ip') : '';
            $key = $device['mac'] !== '' ? $device['mac'] : $ip;
            /* Un appareil vu par les deux voies n'apparaît qu'une fois. */
            if (!isset($found[$key]) || $device['source'] === 'mDNS') {
                $found[$key] = $device;
            }
        }
        usort($found, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        return array('devices' => array_values($found), 'mdns' => $mdns !== null);
    }

    /*
     * Découverte silencieuse, par le mDNS seul : corrige l'adresse des
     * appareils connus qui ont changé d'IP, puis crée ou signale les nouveaux
     * selon la configuration du plugin.
     */
    public static function backgroundDiscovery() {
        $entries = self::mdnsBrowse();
        if (empty($entries)) {
            return;
        }
        $autoCreate = config::byKey('auto_create', __CLASS__, 0) == 1;
        $notified = config::byKey('notified', __CLASS__, array());
        if (!is_array($notified)) {
            $notified = array();
        }
        foreach ($entries as $entry) {
            $eqLogic = $entry['mac'] !== '' ? self::byLogicalId($entry['mac'], __CLASS__) : null;
            if (is_object($eqLogic)) {
                if ($eqLogic->getConfiguration('ip') !== $entry['ip']) {
                    log::add(__CLASS__, 'info', $eqLogic->getHumanName() . ' : '
                        . sprintf(__('nouvelle adresse %s (était %s)', __FILE__), $entry['ip'], $eqLogic->getConfiguration('ip')));
                    $eqLogic->setConfiguration('ip', $entry['ip']);
                    $eqLogic->save();
                }
                continue;
            }
            if ($entry['mac'] !== '' && isset($notified[$entry['mac']]) && !$autoCreate) {
                continue;
            }
            try {
                $device = self::probe($entry['ip']);
            } catch (Throwable $e) {
                continue;
            }
            if ($autoCreate) {
                $eqLogic = self::createFromProbe($device);
                log::add(__CLASS__, 'info', $eqLogic->getHumanName() . ' : ' . __('créé par la découverte automatique.', __FILE__));
                continue;
            }
            $notified[$device['mac']] = time();
            message::add(__CLASS__, sprintf(__('Nouveau WLED détecté : %s (%s). Ajoutez-le depuis la page du plugin, bouton « Rechercher des WLED ».', __FILE__),
                $device['name'] !== '' ? $device['name'] : $device['mac'], $device['ip']), '', 'new' . $device['mac']);
        }
        config::save('notified', $notified, __CLASS__);
    }

    /* Crée un équipement pour un appareil trouvé, ou met son adresse à jour
     * s'il existe déjà : une adresse qui change ne doit pas dédoubler. */
    public static function createFromProbe($_device) {
        $mac = isset($_device['mac']) ? self::normalizeMac($_device['mac']) : '';
        $ip = isset($_device['ip']) ? trim((string) $_device['ip']) : '';
        if ($mac === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new Exception(__('Appareil WLED sans adresse MAC ou IP valable.', __FILE__));
        }
        $eqLogic = self::byLogicalId($mac, __CLASS__);
        if (is_object($eqLogic)) {
            if ($eqLogic->getConfiguration('ip') !== $ip || $eqLogic->applyInfo($_device)) {
                $eqLogic->setConfiguration('ip', $ip);
                $eqLogic->save();
            }
            return $eqLogic;
        }

        $eqLogic = new self();
        $eqLogic->setEqType_name(__CLASS__);
        $eqLogic->setConfiguration('ip', $ip);
        $eqLogic->setConfiguration('verify', 1);
        $eqLogic->applyInfo($_device);
        $eqLogic->setIsEnable(1);
        $eqLogic->setIsVisible(1);
        $eqLogic->setCategory('light', 1);

        $base = $_device['name'] !== '' ? $_device['name'] : 'WLED ' . substr($mac, -6);
        /* Unicité (name, object_id) sur toute la table eqLogic : on numérote
         * plutôt que d'échouer. */
        for ($try = 1; $try <= 10; $try++) {
            $eqLogic->setName($try === 1 ? $base : $base . ' ' . $try);
            try {
                $eqLogic->save();
                break;
            } catch (Throwable $e) {
                if ($try === 10) {
                    throw $e;
                }
            }
        }
        /* Listes et premier relevé tout de suite : l'utilisateur ouvre
         * l'équipement et y trouve des commandes remplies. */
        try {
            $eqLogic->refreshLists();
            $eqLogic->pollNow();
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
        return $eqLogic;
    }

    /* Recopie ce que /json/info dit de l'appareil. Renvoie vrai si quelque
     * chose a changé — l'appelant décide alors d'enregistrer. */
    public function applyInfo($_device) {
        $changed = false;
        foreach (array('mac', 'name' => 'device_name', 'version', 'arch', 'leds', 'rgbw', 'layout', 'matrix_w', 'matrix_h') as $field => $key) {
            if (is_int($field)) {
                $field = $key;
            }
            if (!isset($_device[$field])) {
                continue;
            }
            $value = $_device[$field];
            if ((string) $this->getConfiguration($key, '') !== (string) $value) {
                $this->setConfiguration($key, $value);
                $changed = true;
            }
        }
        return $changed;
    }

    /* ============================================ EFFETS, PALETTES, PRESETS */

    /*
     * Liste des effets proposés, triée comme dans l'interface de WLED (Solid
     * d'abord, puis par nom). Sont écartés les emplacements réservés
     * (« RSVD », « - ») et, sur une bande, les effets purement 2D, qui n'y
     * affichent rien. Les effets sonores sont marqués d'une note : ils ne
     * réagissent qu'avec un micro.
     *
     * Le quatrième champ de /json/fxdata porte les dimensions (0, 1, 2) et les
     * drapeaux sonores (v, f) ; vide, il vaut « 1 ».
     */
    public static function effectList($_names, $_fxdata, $_matrix) {
        $list = array();
        foreach ((array) $_names as $id => $name) {
            $name = trim((string) $name);
            if ($name === '' || $name === 'RSVD' || $name === '-') {
                continue;
            }
            $flags = '1';
            if (isset($_fxdata[$id]) && is_string($_fxdata[$id])) {
                $parts = explode(';', $_fxdata[$id]);
                if (isset($parts[3]) && trim($parts[3]) !== '') {
                    $flags = trim($parts[3]);
                }
            }
            $only2d = strpos($flags, '2') !== false && strpos($flags, '1') === false && strpos($flags, '0') === false;
            if ($only2d && !$_matrix) {
                continue;
            }
            $sound = strpos($flags, 'v') !== false || strpos($flags, 'f') !== false;
            $list[(int) $id] = $name . ($sound ? ' ♪' : '');
        }
        uksort($list, function ($a, $b) use ($list) {
            if ($a === 0 || $b === 0) {
                return $a === 0 ? -1 : 1;
            }
            return strnatcasecmp($list[$a], $list[$b]);
        });
        return $list;
    }

    public static function presetList($_presets) {
        $list = array();
        foreach ((array) $_presets as $id => $preset) {
            if ((int) $id <= 0 || !is_array($preset) || empty($preset)) {
                continue;
            }
            $name = isset($preset['n']) && trim((string) $preset['n']) !== '' ? trim((string) $preset['n']) : 'Preset ' . $id;
            if (isset($preset['playlist'])) {
                $name .= ' (playlist)';
            }
            $list[(int) $id] = $name;
        }
        ksort($list);
        return $list;
    }

    /* Format « clé|libellé;… » des commandes select. Un libellé ne doit
     * contenir ni « ; » ni « | », qui en sont les séparateurs. */
    public static function listValue($_list) {
        $items = array();
        foreach ($_list as $id => $label) {
            $items[] = $id . '|' . str_replace(array(';', '|'), array(',', '/'), $label);
        }
        return implode(';', $items);
    }

    /* Relit sur l'appareil les noms d'effets, de palettes et de presets, et
     * met à jour les listes des commandes select. */
    public function refreshLists() {
        $effects = $this->call('GET', '/json/eff');
        $palettes = $this->call('GET', '/json/pal');
        try {
            $fxdata = $this->call('GET', '/json/fxdata');
        } catch (Throwable $e) {
            /* Absent avant WLED 0.14 : tous les effets sont alors proposés. */
            $fxdata = array();
        }
        try {
            $presets = $this->call('GET', '/presets.json');
        } catch (Throwable $e) {
            $presets = array();
        }
        $this->setCache('fx_names', $effects);
        $this->setCache('pal_names', $palettes);
        $this->setCache('preset_names', self::presetList($presets));

        $this->updateList('effect_set', self::effectList($effects, $fxdata, $this->isMatrix()));
        $pal = array();
        foreach ($palettes as $id => $name) {
            $pal[(int) $id] = (string) $name;
        }
        $this->updateList('palette_set', $pal);
        $this->updateList('preset_set', self::presetList($presets));
        return array('effects' => count($effects), 'palettes' => count($palettes), 'presets' => count(self::presetList($presets)));
    }

    private function updateList($_logicalId, $_list) {
        $cmd = $this->getCmd('action', $_logicalId);
        if (!is_object($cmd)) {
            return;
        }
        $value = self::listValue($_list);
        if ($cmd->getConfiguration('listValue', '') !== $value) {
            $cmd->setConfiguration('listValue', $value);
            $cmd->save();
        }
    }

    /* Numéro d'un effet d'après son nom, sur cet appareil. */
    public function effectIdByName($_name) {
        $wanted = strtolower(trim((string) $_name));
        foreach ((array) $this->getCache('fx_names', array()) as $id => $name) {
            if (strtolower(trim((string) $name)) === $wanted) {
                return (int) $id;
            }
        }
        return null;
    }

    /* ================================================================ ÉTAT */

    public function pollNow() {
        try {
            $data = $this->call('GET', '/json/si');
        } catch (Throwable $e) {
            $this->noteFailure($e->getMessage());
            throw $e;
        }
        $this->ingest($data);
        return true;
    }

    /* Réponse de /json/si : état et identité de l'appareil. */
    public function ingest($_data) {
        $state = isset($_data['state']) && is_array($_data['state']) ? $_data['state'] : array();
        $info = isset($_data['info']) && is_array($_data['info']) ? $_data['info'] : array();
        $this->clearFailure();
        $this->setCache('raw', $_data);
        $this->setCache('raw_at', date('Y-m-d H:i:s'));

        if (self::looksLikeWled($info)) {
            if ($this->applyInfo(self::describe($info, $this->getConfiguration('ip')))) {
                $this->save(true);
            }
            /* Nouvelle version ou nouveau nombre d'effets : les numéros ont pu
             * changer, les listes sont relues. */
            $signature = $info['ver'] . '|' . (isset($info['fxcount']) ? $info['fxcount'] : '') . '|'
                . (isset($info['palcount']) ? $info['palcount'] : '') . '|' . $this->getConfiguration('layout');
            if ($this->getCache('lists_sig', '') !== $signature) {
                try {
                    $this->refreshLists();
                    $this->setCache('lists_sig', $signature);
                } catch (Throwable $e) {
                    log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $e->getMessage());
                }
            }
        }
        $this->publishValues(self::stateValues($state, $info,
            $this->getCache('fx_names', array()), $this->getCache('pal_names', array())));
    }

    public static function mainSegment($_state) {
        $segs = isset($_state['seg']) && is_array($_state['seg']) ? $_state['seg'] : array();
        $main = isset($_state['mainseg']) ? (int) $_state['mainseg'] : 0;
        foreach ($segs as $seg) {
            if (isset($seg['id']) && (int) $seg['id'] === $main) {
                return $seg;
            }
        }
        return isset($segs[0]) && is_array($segs[0]) ? $segs[0] : array();
    }

    /* Valeurs des commandes info tirées d'un état WLED (et de son info, quand
     * elle est connue). Les couleurs, effets et réglages sont ceux du segment
     * principal. */
    public static function stateValues($_state, $_info, $_fxNames, $_palNames) {
        $values = array();
        if (isset($_state['on'])) {
            $values['on'] = $_state['on'] ? 1 : 0;
        }
        if (isset($_state['bri'])) {
            $values['brightness'] = (int) round(((int) $_state['bri']) / 2.55);
        }
        if (isset($_state['ps'])) {
            $values['preset'] = (int) $_state['ps'];
        }
        $seg = self::mainSegment($_state);
        if (isset($seg['col'][0])) {
            $values['color'] = self::colorToHex($seg['col'][0]);
        }
        if (isset($seg['fx'])) {
            $fx = (int) $seg['fx'];
            $values['effect_id'] = $fx;
            $values['effect'] = isset($_fxNames[$fx]) ? (string) $_fxNames[$fx] : '#' . $fx;
        }
        if (isset($seg['pal'])) {
            $pal = (int) $seg['pal'];
            $values['palette_id'] = $pal;
            $values['palette'] = isset($_palNames[$pal]) ? (string) $_palNames[$pal] : '#' . $pal;
        }
        if (isset($seg['sx'])) {
            $values['speed'] = (int) $seg['sx'];
        }
        if (isset($seg['ix'])) {
            $values['intensity'] = (int) $seg['ix'];
        }
        if (isset($_info['live'])) {
            $values['live'] = $_info['live'] ? 1 : 0;
        }
        if (isset($_info['wifi']['signal'])) {
            $values['wifi_signal'] = (int) $_info['wifi']['signal'];
        }
        return $values;
    }

    public static function colorToHex($_color) {
        if (is_string($_color)) {
            $hex = strtolower(ltrim($_color, '#'));
            return '#' . substr(str_pad($hex, 6, '0'), 0, 6);
        }
        $rgb = array_values((array) $_color);
        /* Bande RGBW réglée en blanc pur : les trois canaux couleur sont
         * éteints, seul le blanc brille. */
        if (count($rgb) >= 4 && (int) $rgb[0] === 0 && (int) $rgb[1] === 0 && (int) $rgb[2] === 0 && (int) $rgb[3] > 0) {
            return '#ffffff';
        }
        return sprintf('#%02x%02x%02x', isset($rgb[0]) ? (int) $rgb[0] : 0,
            isset($rgb[1]) ? (int) $rgb[1] : 0, isset($rgb[2]) ? (int) $rgb[2] : 0);
    }

    public static function hexToColor($_hex) {
        $hex = ltrim(trim((string) $_hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $hex)) {
            throw new Exception(__('Couleur illisible :', __FILE__) . ' ' . $_hex);
        }
        $color = array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        if (strlen($hex) === 8) {
            $color[] = hexdec(substr($hex, 6, 2));
        }
        return $color;
    }

    /* ======================================================== VÉRIFICATION */

    /*
     * Écarts entre un ordre envoyé et l'état relu, sous forme de phrases
     * lisibles. Tableau vide : l'ordre a bien été appliqué.
     *
     * Ne sont comparées que les clés présentes dans l'ordre et connues de
     * l'état : ce que l'ordre ne mentionne pas ne le concerne pas.
     */
    public static function mismatches($_sent, $_state) {
        $out = array();
        foreach ((array) $_sent as $key => $value) {
            if (in_array($key, self::UNVERIFIED_KEYS, true)) {
                continue;
            }
            if ($key === 'seg') {
                $out = array_merge($out, self::segMismatches($value, isset($_state['seg']) ? $_state['seg'] : array()));
                continue;
            }
            /* « bri »:0 éteint WLED sans changer la luminosité retenue. */
            if ($key === 'bri' && is_numeric($value) && (int) $value === 0) {
                if (!empty($_state['on'])) {
                    $out[] = 'on = 1 ' . __('au lieu de', __FILE__) . ' 0';
                }
                continue;
            }
            if (!is_array($_state) || !array_key_exists($key, $_state)) {
                continue;
            }
            $out = array_merge($out, self::valueMismatches($key, $value, $_state[$key]));
        }
        return $out;
    }

    private static function valueMismatches($_label, $_sent, $_actual) {
        if (is_array($_sent)) {
            $out = array();
            if (!is_array($_actual)) {
                return $out;
            }
            foreach ($_sent as $key => $value) {
                if (array_key_exists($key, $_actual)) {
                    $out = array_merge($out, self::valueMismatches($_label . '.' . $key, $value, $_actual[$key]));
                }
            }
            return $out;
        }
        if (!self::isAbsolute($_sent)) {
            return array();
        }
        $sent = is_bool($_sent) ? (int) $_sent : $_sent;
        $actual = is_bool($_actual) ? (int) $_actual : $_actual;
        if (is_numeric($sent) && is_numeric($actual)) {
            if ((float) $sent == (float) $actual) {
                return array();
            }
        } elseif ((string) $sent === (string) $actual) {
            return array();
        }
        return array($_label . ' = ' . var_export($actual, true) . ' ' . __('au lieu de', __FILE__) . ' ' . var_export($sent, true));
    }

    /* Une valeur relative (« ~10 », « ~-10 », « t » pour basculer, « r »
     * pour aléatoire) ne se compare pas à l'état relu. */
    private static function isAbsolute($_value) {
        if (!is_string($_value)) {
            return true;
        }
        $v = trim($_value);
        return !($v === 't' || $v === 'r' || strpos($v, '~') === 0 || strpos($v, '!') === 0);
    }

    /* Un ordre qui contient une valeur relative ne se renvoie pas : un
     * « +10 » répété trois fois ferait « +30 ». */
    public static function isIdempotent($_fragment) {
        foreach ((array) $_fragment as $key => $value) {
            if (is_array($value)) {
                if (!self::isIdempotent($value)) {
                    return false;
                }
            } elseif (!self::isAbsolute($value) || ($key === 'on' && $value === 't')) {
                return false;
            }
        }
        return true;
    }

    /*
     * « seg » est soit un objet — sans « id », WLED l'applique à tous les
     * segments sélectionnés, ou au principal si aucun ne l'est — soit une
     * liste, dont chaque élément vise son « id » ou, à défaut, son rang.
     */
    public static function segMismatches($_sent, $_segs) {
        $segs = array();
        foreach ((array) $_segs as $rank => $seg) {
            if (is_array($seg)) {
                $segs[isset($seg['id']) ? (int) $seg['id'] : (int) $rank] = $seg;
            }
        }
        $orders = array();
        if (is_array($_sent) && array_keys($_sent) !== range(0, count($_sent) - 1)) {
            if (isset($_sent['id'])) {
                $orders[] = array((int) $_sent['id'], $_sent);
            } else {
                $selected = array();
                foreach ($segs as $id => $seg) {
                    if (!empty($seg['sel'])) {
                        $selected[] = $id;
                    }
                }
                if (empty($selected) && !empty($segs)) {
                    $selected[] = (int) key($segs);
                }
                foreach ($selected as $id) {
                    $orders[] = array($id, $_sent);
                }
            }
        } else {
            foreach ((array) $_sent as $rank => $order) {
                if (is_array($order)) {
                    $orders[] = array(isset($order['id']) ? (int) $order['id'] : (int) $rank, $order);
                }
            }
        }

        $out = array();
        foreach ($orders as $pair) {
            list($id, $order) = $pair;
            /* Un segment qu'on supprime (« stop »:0) n'a plus rien à relire. */
            if (isset($order['stop']) && (int) $order['stop'] === 0) {
                continue;
            }
            if (!isset($segs[$id])) {
                $out[] = 'seg ' . $id . ' ' . __('absent', __FILE__);
                continue;
            }
            foreach ($order as $key => $value) {
                if (in_array($key, array('id', 'i', 'sel', 'len', 'fxdef', 'set', 'bm'), true)) {
                    continue;
                }
                if ($key === 'col') {
                    $out = array_merge($out, self::colorMismatches($id, $value, isset($segs[$id]['col']) ? $segs[$id]['col'] : array()));
                    continue;
                }
                if (array_key_exists($key, $segs[$id])) {
                    $out = array_merge($out, self::valueMismatches('seg ' . $id . '.' . $key, $value, $segs[$id][$key]));
                }
            }
        }
        return $out;
    }

    private static function colorMismatches($_id, $_sent, $_actual) {
        $out = array();
        foreach ((array) $_sent as $slot => $color) {
            if ($color === null || $color === array() || $color === '') {
                continue;
            }
            try {
                $wanted = is_string($color) ? self::hexToColor($color) : array_values((array) $color);
            } catch (Throwable $e) {
                continue;
            }
            $have = isset($_actual[$slot]) ? (is_string($_actual[$slot]) ? self::hexToColor($_actual[$slot]) : array_values((array) $_actual[$slot])) : array();
            $n = min(count($wanted), count($have));
            $same = $n > 0;
            for ($i = 0; $i < $n; $i++) {
                $same = $same && (int) $wanted[$i] === (int) $have[$i];
            }
            if (!$same) {
                $out[] = 'seg ' . $_id . '.col[' . $slot . '] = ' . json_encode($have) . ' ' . __('au lieu de', __FILE__) . ' ' . json_encode($wanted);
            }
        }
        return $out;
    }

    /*
     * Envoi d'un ordre, vérifié. À chaque essai :
     *   1. POST /json/state avec « v »:true, qui renvoie l'état appliqué ;
     *   2. comparaison avec ce qui a été demandé ;
     *   3. en cas d'écart, une relecture après un court délai, car WLED
     *      applique certains ordres au tour de boucle suivant (preset) ;
     *   4. écart persistant ou appareil muet : nouvel essai, avec une attente
     *      qui double à chaque fois.
     * Rend l'état final ; lève une exception si l'ordre n'a pas pu être
     * appliqué, après avoir renseigné les commandes de vérification.
     */
    public function sendState($_fragment) {
        if (!is_array($_fragment) || empty($_fragment)) {
            throw new Exception(__('Ordre WLED vide.', __FILE__));
        }
        $verify = (int) $this->getConfiguration('verify', 1) === 1;
        $tries = self::isIdempotent($_fragment) ? self::tries() : 1;
        $body = $_fragment;
        $body['v'] = true;
        $problem = '';
        log::add(__CLASS__, 'debug', $this->getHumanName() . ' → ' . json_encode($_fragment));

        for ($attempt = 1; $attempt <= $tries; $attempt++) {
            if ($attempt > 1) {
                usleep(min(4000, 500 * (1 << ($attempt - 2))) * 1000);
            }
            try {
                $answer = $this->call('POST', '/json/state', $body);
                $state = isset($answer['state']) && is_array($answer['state']) ? $answer['state'] : $answer;
                /* Firmware trop ancien pour « v » : il répond {"success":true}. */
                if (!isset($state['on'])) {
                    $state = $this->call('GET', '/json/state');
                }
                $this->clearFailure();
                $diff = $verify ? self::mismatches($_fragment, $state) : array();
                if (!empty($diff)) {
                    usleep(self::SETTLE_MS * 1000);
                    $state = $this->call('GET', '/json/state');
                    $diff = self::mismatches($_fragment, $state);
                }
            } catch (Throwable $e) {
                $problem = $e->getMessage();
                $this->noteFailure($problem);
                log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . sprintf(__('essai %d/%d : %s', __FILE__), $attempt, $tries, $problem));
                continue;
            }
            $this->publishValues(self::stateValues($state, array(),
                $this->getCache('fx_names', array()), $this->getCache('pal_names', array())));
            if (empty($diff)) {
                $this->verifyDone(true, $verify ? ($attempt === 1 ? __('OK', __FILE__)
                    : sprintf(__('OK après %d essais', __FILE__), $attempt)) : __('non vérifié', __FILE__));
                return $state;
            }
            $problem = implode(' ; ', $diff);
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . sprintf(__('essai %d/%d, écart : %s', __FILE__), $attempt, $tries, $problem));
        }

        $text = sprintf(__('Ordre non appliqué après %d essai(s) : %s', __FILE__), $tries, $problem);
        $this->verifyDone(false, $text);
        throw new Exception($this->getHumanName() . ' : ' . $text);
    }

    private function verifyDone($_ok, $_text) {
        $this->publishCmd('verify_ok', $_ok ? 1 : 0);
        $this->publishCmd('verify_detail', date('H:i:s') . ' ' . $_text);
        if ($_ok) {
            if ($this->getCache('verify_failed', 0)) {
                $this->setCache('verify_failed', 0);
                message::removeAll(__CLASS__, 'verify' . $this->getId());
            }
            return;
        }
        $this->setCache('verify_failed', 1);
        log::add(__CLASS__, 'warning', $this->getHumanName() . ' : ' . $_text);
        message::add(__CLASS__, $this->getHumanName() . ' : ' . $_text, '', 'verify' . $this->getId());
    }

    /* ============================================================ ACTIONS */

    public function runAction($_logicalId, $_options) {
        switch ($_logicalId) {
            case 'refresh':
                return $this->pollNow();
            case 'on_set':
                return $this->sendState(array('on' => true));
            case 'off_set':
                return $this->sendState(array('on' => false));
            case 'toggle':
                /* « on »:"t" ne se vérifie pas : on lit l'état et on envoie
                 * l'inverse, explicitement. */
                try {
                    $state = $this->call('GET', '/json/state');
                    $on = !empty($state['on']);
                } catch (Throwable $e) {
                    $cmd = $this->getCmd('info', 'on');
                    $on = is_object($cmd) && (int) $cmd->execCmd() === 1;
                }
                return $this->sendState(array('on' => !$on));
            case 'brightness_set':
                $pct = max(0, min(100, (int) (isset($_options['slider']) ? $_options['slider'] : 100)));
                if ($pct === 0) {
                    return $this->sendState(array('on' => false));
                }
                return $this->sendState(array('on' => true, 'bri' => max(1, (int) round($pct * 2.55))));
            case 'color_set':
                $color = self::hexToColor(isset($_options['color']) ? $_options['color'] : '');
                return $this->sendState(array('on' => true, 'seg' => array('col' => array($color))));
            case 'effect_set':
                return $this->sendState(array('on' => true, 'seg' => array('fx' => $this->resolveEffect($_options))));
            case 'palette_set':
                $pal = isset($_options['select']) ? $_options['select'] : '';
                if (!is_numeric($pal)) {
                    throw new Exception(__('Palette illisible :', __FILE__) . ' ' . $pal);
                }
                return $this->sendState(array('seg' => array('pal' => (int) $pal)));
            case 'speed_set':
            case 'intensity_set':
                $value = max(0, min(255, (int) (isset($_options['slider']) ? $_options['slider'] : 128)));
                return $this->sendState(array('seg' => array($_logicalId === 'speed_set' ? 'sx' : 'ix' => $value)));
            case 'preset_set':
                $ps = isset($_options['select']) ? $_options['select'] : '';
                if (!is_numeric($ps) || (int) $ps <= 0) {
                    throw new Exception(__('Preset illisible :', __FILE__) . ' ' . $ps);
                }
                return $this->sendState(array('ps' => (int) $ps));
            case 'json_set':
                $fragment = json_decode(isset($_options['message']) ? (string) $_options['message'] : '', true);
                if (!is_array($fragment) || empty($fragment)) {
                    throw new Exception(__('JSON illisible : attendu un objet comme {"on":true,"seg":{"fx":1}}.', __FILE__));
                }
                return $this->sendState($fragment);
        }
        return true;
    }

    /* L'effet vient d'une liste (numéro) ou d'un scénario, qui peut aussi le
     * désigner par son nom : un nom survit aux mises à jour de WLED. */
    private function resolveEffect($_options) {
        $wanted = isset($_options['select']) ? trim((string) $_options['select']) : '';
        if ($wanted === '') {
            throw new Exception(__('Aucun effet choisi.', __FILE__));
        }
        if (is_numeric($wanted)) {
            return (int) $wanted;
        }
        $id = $this->effectIdByName(preg_replace('/\s*♪$/u', '', $wanted));
        if ($id === null) {
            throw new Exception(sprintf(__('Effet inconnu sur ce WLED : %s', __FILE__), $wanted));
        }
        return $id;
    }

    /* =========================================================== COMMANDES */

    /*
     * Création idempotente. Une commande existante n'est jamais récrite : son
     * nom, sa visibilité et son historisation appartiennent à l'utilisateur.
     */
    public function createCommands() {
        if ($this->getId() == '') {
            return;
        }
        $on = $this->addCmdIfMissing('on', 'Etat', 'info', 'binary', array('order' => 1, 'generic' => 'LIGHT_STATE_BOOL'));
        $bri = $this->addCmdIfMissing('brightness', 'Luminosité', 'info', 'numeric', array(
            'order' => 2, 'generic' => 'LIGHT_BRIGHTNESS', 'unite' => '%', 'min' => 0, 'max' => 100));
        $color = $this->addCmdIfMissing('color', 'Couleur', 'info', 'string', array('order' => 3, 'generic' => 'LIGHT_COLOR'));
        $this->addCmdIfMissing('effect', 'Effet', 'info', 'string', array('order' => 4, 'isVisible' => 1));
        $effectId = $this->addCmdIfMissing('effect_id', 'Numéro d\'effet', 'info', 'numeric', array('order' => 5));
        $this->addCmdIfMissing('palette', 'Palette', 'info', 'string', array('order' => 6));
        $paletteId = $this->addCmdIfMissing('palette_id', 'Numéro de palette', 'info', 'numeric', array('order' => 7));
        $speed = $this->addCmdIfMissing('speed', 'Vitesse', 'info', 'numeric', array('order' => 8, 'min' => 0, 'max' => 255));
        $intensity = $this->addCmdIfMissing('intensity', 'Intensité', 'info', 'numeric', array('order' => 9, 'min' => 0, 'max' => 255));
        $preset = $this->addCmdIfMissing('preset', 'Preset actif', 'info', 'numeric', array('order' => 10));
        $this->addCmdIfMissing('live', 'Pilotage externe', 'info', 'binary', array('order' => 11));
        $this->addCmdIfMissing('online', 'En ligne', 'info', 'binary', array('order' => 12));
        $this->addCmdIfMissing('wifi_signal', 'Signal Wi-Fi', 'info', 'numeric', array('order' => 13, 'unite' => '%', 'min' => 0, 'max' => 100));
        $this->addCmdIfMissing('verify_ok', 'Vérification', 'info', 'binary', array('order' => 14));
        $this->addCmdIfMissing('verify_detail', 'Dernière vérification', 'info', 'string', array('order' => 15));

        $this->addCmdIfMissing('on_set', 'Allumer', 'action', 'other', array('order' => 100, 'isVisible' => 1, 'generic' => 'LIGHT_ON', 'value' => $on));
        $this->addCmdIfMissing('off_set', 'Éteindre', 'action', 'other', array('order' => 101, 'isVisible' => 1, 'generic' => 'LIGHT_OFF', 'value' => $on));
        $this->addCmdIfMissing('toggle', 'Basculer', 'action', 'other', array('order' => 102, 'generic' => 'LIGHT_TOGGLE', 'value' => $on));
        $this->addCmdIfMissing('brightness_set', 'Régler la luminosité', 'action', 'slider', array(
            'order' => 103, 'isVisible' => 1, 'generic' => 'LIGHT_SLIDER', 'value' => $bri, 'min' => 0, 'max' => 100));
        $this->addCmdIfMissing('color_set', 'Régler la couleur', 'action', 'color', array(
            'order' => 104, 'isVisible' => 1, 'generic' => 'LIGHT_SET_COLOR', 'value' => $color));
        $this->addCmdIfMissing('effect_set', 'Choisir un effet', 'action', 'select', array(
            'order' => 105, 'isVisible' => 1, 'generic' => 'LIGHT_MODE', 'value' => $effectId));
        $this->addCmdIfMissing('palette_set', 'Choisir une palette', 'action', 'select', array(
            'order' => 106, 'isVisible' => 1, 'value' => $paletteId));
        $this->addCmdIfMissing('speed_set', 'Régler la vitesse', 'action', 'slider', array(
            'order' => 107, 'isVisible' => 1, 'value' => $speed, 'min' => 0, 'max' => 255));
        $this->addCmdIfMissing('intensity_set', 'Régler l\'intensité', 'action', 'slider', array(
            'order' => 108, 'isVisible' => 1, 'value' => $intensity, 'min' => 0, 'max' => 255));
        $this->addCmdIfMissing('preset_set', 'Appliquer un preset', 'action', 'select', array('order' => 109, 'value' => $preset));
        $this->addCmdIfMissing('json_set', 'Envoyer un état JSON', 'action', 'message', array('order' => 110));
        $this->addCmdIfMissing('refresh', 'Rafraîchir', 'action', 'other', array('order' => 120, 'isVisible' => 1));
    }

    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd($_type, $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }
        $cmd = new wledbeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        /* Unicité (eqLogic_id, name) en base : un nom déjà pris ferait échouer
         * tout l'enregistrement de l'équipement. Nettoyé comme le fera
         * setName(), sans quoi la recherche ne le trouverait jamais. */
        $name = cleanComponanteName(__($_name, __FILE__));
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 0);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);
        if (isset($_options['order']))      { $cmd->setOrder($_options['order']); }
        if (!empty($_options['unite']))     { $cmd->setUnite($_options['unite']); }
        if (!empty($_options['generic']))   { $cmd->setGeneric_type($_options['generic']); }
        if (isset($_options['min']))        { $cmd->setConfiguration('minValue', $_options['min']); }
        if (isset($_options['max']))        { $cmd->setConfiguration('maxValue', $_options['max']); }
        if (isset($_options['value']) && is_object($_options['value'])) {
            $cmd->setValue($_options['value']->getId());
        }
        $cmd->save();
        return $cmd;
    }

    public static function rebuildCommands() {
        foreach (self::byType(__CLASS__) as $eqLogic) {
            try {
                $eqLogic->createCommands();
                $eqLogic->setCache('lists_sig', '');
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    private function publishValues($_values) {
        foreach ($_values as $logicalId => $value) {
            $this->publishCmd($logicalId, $value);
        }
    }

    /* Écriture d'une valeur. Ce nom, et surtout pas setCmd() : utils::a2o()
     * appelle « set » + chaque clé du formulaire, et la page envoie « cmd ». */
    private function publishCmd($_logicalId, $_value) {
        if ($_value === null) {
            return;
        }
        $cmd = $this->getCmd('info', $_logicalId);
        if (!is_object($cmd)) {
            return;
        }
        $this->checkAndUpdateCmd($cmd, $_value);
    }

    /* ==================================================== ÉCHECS ET ÉTAT */

    private function noteFailure($_message) {
        $failures = (int) $this->getCache('failures', 0) + 1;
        $this->setCache('failures', $failures);
        $this->setCache('problem', $_message);
        $this->publishCmd('online', 0);
        /* Un seul avertissement par panne : un journal qui répète la même
         * ligne chaque minute ne se lit plus. */
        log::add(__CLASS__, $failures === self::OFFLINE_AFTER ? 'warning' : 'debug', $this->getHumanName() . ' : ' . $_message);
    }

    private function clearFailure() {
        $failures = (int) $this->getCache('failures', 0);
        if ($failures >= self::OFFLINE_AFTER) {
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('de nouveau joignable.', __FILE__));
        }
        if ($failures > 0) {
            $this->setCache('failures', 0);
            $this->setCache('problem', '');
        }
        $this->publishCmd('online', 1);
    }

    public function toAjax() {
        return array(
            'id'        => $this->getId(),
            'online'    => (int) $this->getCache('failures', 0) === 0 && $this->getCache('raw_at', '') !== '',
            'failures'  => (int) $this->getCache('failures', 0),
            'problem'   => (string) $this->getCache('problem', ''),
            'fetchedAt' => (string) $this->getCache('raw_at', ''),
            'effects'   => count((array) $this->getCache('fx_names', array())),
            'palettes'  => count((array) $this->getCache('pal_names', array())),
            'presets'   => count((array) $this->getCache('preset_names', array())),
            'raw'       => $this->getCache('raw', array()),
        );
    }
}

/* Obligatoire même réduite : sans elle, le coeur refuse de créer ou d'ouvrir
 * un équipement du plugin. */
class wledbeCmd extends cmd {

    public function execute($_options = array()) {
        if ($this->getType() !== 'action') {
            return;
        }
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            return;
        }
        $eqLogic->runAction($this->getLogicalId(), $_options);
    }
}
