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

    /* Toutes les combien de secondes la garde relit une scène protégée. */
    const GUARD_EVERY = 10;

    /* Délai entre deux tentatives de restauration d'un appareil qui ne
     * répondait pas à la fin d'une scène. */
    const RESTORE_RETRY = 30;

    /* Clés d'un ordre qui ne décrivent pas un état : rien à vérifier. */
    const UNVERIFIED_KEYS = array('v', 'transition', 'tt', 'tb', 'time', 'psave', 'pdel', 'rb', 'lor',
                                  'np', 'ib', 'sb', 'o', 'rmcpal', 'playlist', 'pl');

    /* ================================================================ CRON */

    /*
     * Une fois par minute :
     *   - les scènes en retard sont avancées. C'est le travail du démon ; le
     *     cron le fait s'il est arrêté, et de toute façon pour un réveil en
     *     retard de plus de trente secondes : une scène ne doit jamais rester
     *     allumée parce que le démon ne joint plus Jeedom ;
     *   - l'état de tous les appareils est relu, en parallèle : le cron de
     *     tous les plugins passe dans un seul processus, un WLED muet ne doit
     *     pas y faire attendre les autres.
     */
    public static function cron() {
        $daemonOk = self::deamon_info()['state'] == 'ok';
        foreach (self::getSchedule()['timers'] as $timer) {
            if ($timer['at'] > time() || ($daemonOk && $timer['at'] > time() - 30)) {
                continue;
            }
            $eqLogic = self::byId($timer['eq']);
            if (is_object($eqLogic)) {
                try {
                    $eqLogic->tick();
                } catch (Throwable $e) {
                    log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
                }
            }
        }

        $slowTurn = ((int) date('i')) % 5 === 0;
        $due = array();
        $urls = array();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if (!$eqLogic->isConfigured()) {
                continue;
            }
            if ((int) $eqLogic->getCache('failures', 0) >= self::OFFLINE_AFTER && !$slowTurn) {
                continue;
            }
            $due[$eqLogic->getId()] = $eqLogic;
            $urls[$eqLogic->getId()] = 'http://' . $eqLogic->getConfiguration('ip') . '/json/si';
        }
        $timeout = self::timeout() * 1000;
        foreach (self::multiGet($urls, min(3000, $timeout), $timeout) as $id => $body) {
            $eqLogic = $due[$id];
            try {
                $data = $body === null ? null : json_decode($body, true);
                if (!is_array($data)) {
                    $eqLogic->noteFailure(__('pas de réponse au relevé', __FILE__));
                    continue;
                }
                $eqLogic->ingest($data);
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

    /* =============================================================== DÉMON */

    /*
     * Le démon n'est qu'une minuterie. Il demande au plugin quand le réveiller
     * (getSchedule()), et le rappelle à l'heure dite ; tout le reste — scènes,
     * priorités, restauration, garde — se décide ici, dans tick(). Il ne
     * charge pas le coeur et ne parle jamais à un WLED.
     */
    public static function deamon_info() {
        $return = array('log' => __CLASS__ . 'd', 'state' => 'nok', 'launchable' => 'ok');
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '' && @posix_getsid((int) $pid)) {
                $return['state'] = 'ok';
            } else {
                @unlink($pid_file);
            }
        }
        /* Un démon vivant qui ne joint plus Jeedom (clé API changée, accès
         * API restreint) ne réveille plus rien : il est déclaré arrêté, pour
         * que la gestion automatique le relance et que le cron prenne le
         * relais entre-temps. Il rappelle au moins chaque minute. */
        if ($return['state'] == 'ok' && time() - (int) @filemtime($pid_file) > 120
            && time() - (int) cache::byKey('wledbe::daemon_seen')->getValue(0) > 150) {
            $return['state'] = 'nok';
        }
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();
        $daemon = realpath(__DIR__ . '/../../resources/wledbed/wledbed.php');
        $cmd  = 'php ' . escapeshellarg($daemon);
        $cmd .= ' --callback ' . escapeshellarg(self::getCallbackUrl());
        $cmd .= ' --pid ' . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/deamon.pid');
        $cmd .= ' --stamp ' . escapeshellarg(self::stampFile());
        $cmd .= ' --loglevel ' . escapeshellarg(log::convertLogLevel(log::getLogLevel(__CLASS__)));
        $cmd .= ' --timezone ' . escapeshellarg(date_default_timezone_get());

        /* La clé API passe par un fichier lisible du seul www-data, que le
         * démon efface après l'avoir lu : ni en argument ni dans un « echo »,
         * que ps montre à n'importe quel utilisateur local. */
        $keyFile = jeedom::getTmpFolder(__CLASS__) . '/daemon.key';
        @unlink($keyFile);
        $old = umask(0077);
        file_put_contents($keyFile, jeedom::getApiKey(__CLASS__));
        umask($old);
        $cmd .= ' --keyfile ' . escapeshellarg($keyFile);
        $full = $cmd . ' >> ' . log::getPathToLog(__CLASS__ . 'd') . ' 2>&1 &';
        log::add(__CLASS__, 'info', __('Lancement du démon', __FILE__));
        exec($full);

        for ($i = 1; $i <= 20; $i++) {
            if (self::deamon_info()['state'] == 'ok') {
                message::removeAll(__CLASS__, 'unableStartDeamon');
                return true;
            }
            sleep(1);
        }
        log::add(__CLASS__, 'error', __('Le démon n\'a pas démarré. Consultez le journal', __FILE__) . ' ' . __CLASS__ . 'd.');
        return false;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '') {
                system::kill($pid);
            }
            @unlink($pid_file);
        }
        system::kill('resources/wledbed/wledbed.php');
        return true;
    }

    public static function getCallbackUrl() {
        return network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')
             . '/plugins/wledbe/core/php/jeeWledbe.php';
    }

    public static function stampFile() {
        return jeedom::getTmpFolder(__CLASS__) . '/schedule.stamp';
    }

    /* Le démon relit le planning quand le contenu de ce fichier change. Un
     * contenu et non une date : filemtime() ne distingue pas deux
     * changements dans la même seconde. */
    public static function notifyDaemon() {
        @file_put_contents(self::stampFile(), sprintf('%.6f', microtime(true)));
    }

    /* Les prochains réveils, appareil par appareil. Autosuffisant : le démon
     * ne peut rien relire d'autre. */
    public static function getSchedule() {
        $timers = array();
        $live = array();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            $at = $eqLogic->nextWake();
            if ($at !== null) {
                $timers[] = array('eq' => (int) $eqLogic->getId(), 'at' => $at, 'name' => $eqLogic->getHumanName());
            }
            if ($eqLogic->isConfigured() && !$eqLogic->isGroup() && config::byKey('live', __CLASS__, 1) == 1) {
                $live[] = array('eq' => (int) $eqLogic->getId(), 'ip' => $eqLogic->getConfiguration('ip'), 'name' => $eqLogic->getHumanName());
            }
        }
        return array('timers' => $timers, 'live' => $live);
    }

    /*
     * État poussé par un WLED sur la connexion WebSocket que tient le démon :
     * même contenu que /json/si, traité par le même chemin. Et si une scène
     * sous garde vient d'être défaite, la garde est avancée à maintenant au
     * lieu d'attendre son prochain tour.
     */
    public function ingestLive($_data) {
        if (!is_array($_data) || !isset($_data['state']) || !is_array($_data['state'])) {
            return;
        }
        $this->ingest($_data);
        $this->withLock(function () use ($_data) {
            $top = self::topEntry($this->stack());
            $fragment = $this->sceneGet('applied_fragment', null);
            /* Au plus une garde avancée toutes les cinq secondes : une scène
             * que l'appareil refuse d'appliquer ne doit pas faire boucler
             * garde, renvoi et nouvel état poussé. */
            if ($top !== null && !empty($top['guard']) && is_array($fragment)
                && $top['key'] === (string) $this->sceneGet('applied_key', '')
                && time() - (int) $this->sceneGet('guard_ran', 0) >= 5
                && (int) $this->sceneGet('guard_at', 0) > time()
                && !empty(self::mismatches($fragment, $_data['state']))) {
                $this->sceneSet(array('guard_at' => time()));
                self::notifyDaemon();
            }
        }, false);
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
        if ($this->isGroup()) {
            $this->setConfiguration('members', self::parseMembers($this->getConfiguration('members', array())));
            return;
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
        if ($this->isGroup()) {
            $this->refreshGroupLists();
            $this->refreshGroup();
            return;
        }
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
        /* Un plafond : sans lui, deux interfaces réseau font plus de cinq
         * cents connexions ouvertes d'un coup. */
        if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
            curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 128);
        }
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
            /* select() rend -1 quand il n'a rien à surveiller : sans cette
             * pause, la boucle tournerait à vide. */
            if ($active && curl_multi_select($multi, 0.2) === -1) {
                usleep(10000);
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
            'name'     => self::deviceText(isset($_info['name']) ? $_info['name'] : '', 64),
            'version'  => self::deviceText(isset($_info['ver']) ? $_info['ver'] : '', 32),
            'arch'     => self::deviceText(isset($_info['arch']) ? $_info['arch'] : '', 32),
            'leds'     => isset($leds['count']) ? (int) $leds['count'] : 0,
            'rgbw'     => !empty($leds['rgbw']) ? 1 : 0,
            'layout'   => $matrix !== null ? 'matrix' : 'strip',
            'matrix_w' => $matrix !== null && isset($matrix['w']) ? (int) $matrix['w'] : 0,
            'matrix_h' => $matrix !== null && isset($matrix['h']) ? (int) $matrix['h'] : 0,
        );
    }

    /* Un texte fourni par un appareil : n'importe qui sur le réseau local
     * peut renommer un WLED, ou se faire passer pour un. Jamais de balisage,
     * jamais de longueur démesurée. */
    public static function deviceText($_value, $_max) {
        if (!is_scalar($_value)) {
            return '';
        }
        $text = trim(strip_tags((string) $_value));
        $text = str_replace(array('<', '>'), '', $text);
        return mb_substr($text, 0, $_max);
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
        exec('timeout ' . (int) self::MDNS_SECONDS . ' avahi-browse -rtpk _wled._tcp 2>/dev/null', $out, $rc);
        /* 124 : coupé par timeout, ce qu'on a lu reste bon. Tout autre échec
         * (démon avahi arrêté) : pas de mDNS, et l'interface doit le dire. */
        if ($rc !== 0 && $rc !== 124 && empty($out)) {
            return null;
        }
        return self::parseAvahi($out);
    }

    /* Interfaces qui ne mènent pas au réseau de la maison : ponts Docker et
     * machines virtuelles, VPN. Les balayer ajouterait 254 requêtes chacune,
     * pour rien. */
    const VIRTUAL_INTERFACES = '/^(docker|br-|veth|virbr|lxc|lxd|tun|tap|wg|tailscale|zt|vmnet|vboxnet|cni|flannel|kube)/';

    public static function localIps() {
        $ips = array();
        $out = array();
        @exec('ip -4 -o addr show scope global 2>/dev/null', $out);
        foreach ($out as $line) {
            /* « 2: ens18    inet 192.168.1.10/24 brd … » */
            if (preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)\//', $line, $m)
                && !preg_match(self::VIRTUAL_INTERFACES, $m[1])) {
                $ips[] = $m[2];
            }
        }
        if (empty($out)) {
            @exec('hostname -I 2>/dev/null', $out);
            foreach (preg_split('/\s+/', trim(implode(' ', $out))) as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($ip, '127.') !== 0
                    && strpos($ip, '172.17.') !== 0) {
                    $ips[] = $ip;
                }
            }
        }
        if (empty($ips)) {
            $internal = parse_url(network::getNetworkAccess('internal'), PHP_URL_HOST);
            if (filter_var($internal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = $internal;
            }
        }
        return array_values(array_unique($ips));
    }

    /* Préfixe à balayer (trois octets) d'après la saisie : « 192.168.1 »,
     * « 192.168.1.0 » ou « 192.168.1.0/24 ». Le balayage couvre un /24 et
     * rien d'autre : un autre masque est refusé plutôt que tronqué en
     * silence. */
    public static function subnetPrefix($_subnet) {
        $subnet = trim((string) $_subnet);
        if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:\.(\d{1,3}))?(?:\/(\d{1,2}))?$/', $subnet, $m)) {
            throw new Exception(__('Sous-réseau illisible : saisissez par exemple 192.168.1.0/24.', __FILE__));
        }
        foreach (array(1, 2, 3, 4) as $i) {
            if (isset($m[$i]) && $m[$i] !== '' && (int) $m[$i] > 255) {
                throw new Exception(__('Sous-réseau illisible : saisissez par exemple 192.168.1.0/24.', __FILE__));
            }
        }
        if (isset($m[5]) && $m[5] !== '' && (int) $m[5] !== 24) {
            throw new Exception(__('Seul un sous-réseau en /24 (256 adresses) peut être parcouru. Pour un réseau plus grand, faites plusieurs recherches.', __FILE__));
        }
        return (int) $m[1] . '.' . (int) $m[2] . '.' . (int) $m[3];
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
            $prefixes[] = self::subnetPrefix($subnet);
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
        /* Un appareil signalé il y a plus d'un mois l'est à nouveau : son
         * équipement a pu être supprimé depuis. */
        foreach ($notified as $mac => $at) {
            if ((int) $at < time() - 30 * 86400) {
                unset($notified[$mac]);
            }
        }
        foreach ($entries as $entry) {
            $eqLogic = $entry['mac'] !== '' ? self::byLogicalId($entry['mac'], __CLASS__) : null;
            if (is_object($eqLogic)) {
                if ($eqLogic->getConfiguration('ip') !== $entry['ip']) {
                    /* Le TXT mDNS n'est qu'une annonce, que n'importe qui peut
                     * émettre : l'appareil est interrogé, et sa MAC vérifiée,
                     * avant de lui confier l'équipement. */
                    try {
                        $device = self::probe($entry['ip']);
                    } catch (Throwable $e) {
                        continue;
                    }
                    if ($device['mac'] !== $eqLogic->getLogicalId()) {
                        continue;
                    }
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
            $moved = $eqLogic->getConfiguration('ip') !== $ip;
            $changed = $eqLogic->applyInfo($_device);
            if ($moved || $changed) {
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
     * chose a changé — l'appelant décide alors d'enregistrer. La MAC n'est
     * écrite qu'une fois : c'est l'identité de l'équipement, un autre
     * appareil qui répondrait à la même adresse ne doit pas la remplacer. */
    public function applyInfo($_device) {
        $changed = false;
        foreach (array('mac', 'name' => 'device_name', 'version', 'arch', 'leds', 'rgbw', 'layout', 'matrix_w', 'matrix_h') as $field => $key) {
            if (is_int($field)) {
                $field = $key;
            }
            if (!isset($_device[$field])) {
                continue;
            }
            if ($key === 'mac' && ($_device['mac'] === '' || (string) $this->getConfiguration('mac', '') !== '')) {
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
            $name = self::deviceText($name, 48);
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
            $name = isset($preset['n']) ? self::deviceText($preset['n'], 48) : '';
            if ($name === '') {
                $name = 'Preset ' . $id;
            }
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
        $effects = array_map(function ($n) { return wledbe::deviceText($n, 48); }, $effects);
        $palettes = array_map(function ($n) { return wledbe::deviceText($n, 48); }, $palettes);
        $this->setCache('fx_names', $effects);
        $this->setCache('pal_names', $palettes);
        $this->setCache('preset_names', self::presetList($presets));

        $this->updateList('effect_set', self::effectList($effects, $fxdata, $this->isMatrix()));
        $pal = array();
        foreach ($palettes as $id => $name) {
            $pal[(int) $id] = self::deviceText($name, 48);
        }
        $this->updateList('palette_set', $pal);
        $this->updateList('preset_set', self::presetList($presets));
        foreach ($this->groups() as $group) {
            $group->refreshGroupLists();
        }
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

        /* Un autre WLED répond à cette adresse (IP réattribuée par le DHCP,
         * deux appareils qui l'ont échangée) : rien de ce qu'il dit ne
         * concerne cet équipement. Compter un échec suffit : au troisième, la
         * découverte horaire cherche la nouvelle adresse du bon appareil. */
        $known = (string) $this->getConfiguration('mac', '');
        $mac = self::normalizeMac(isset($info['mac']) ? $info['mac'] : '');
        if ($known !== '' && $mac !== '' && $mac !== $known) {
            $this->noteFailure(sprintf(__('un autre WLED (%s) répond à l\'adresse %s', __FILE__), $mac, $this->getConfiguration('ip')));
            return;
        }

        $this->clearFailure();
        $this->setCache('raw', $_data);
        $this->setCache('raw_at', date('Y-m-d H:i:s'));

        if (self::looksLikeWled($info)) {
            $device = self::describe($info, $this->getConfiguration('ip'));
            /* L'objet a pu être chargé bien avant ce relevé : on repart de la
             * base, pour ne pas y réécrire un nom ou une adresse que
             * l'utilisateur vient de changer. */
            if ($this->getId() != '' && $this->applyInfo($device)) {
                $this->refresh();
                if ($this->applyInfo($device)) {
                    $this->save();
                }
            }
            /* Nouvelle version ou nouveau nombre d'effets : les numéros ont pu
             * changer, les listes sont relues. */
            $signature = $device['version'] . '|' . (isset($info['fxcount']) ? (int) $info['fxcount'] : '') . '|'
                . (isset($info['palcount']) ? (int) $info['palcount'] : '') . '|' . $this->getConfiguration('layout');
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
                $out = array_merge($out, self::segMismatches($value, isset($_state['seg']) ? $_state['seg'] : array(),
                    isset($_state['mainseg']) ? (int) $_state['mainseg'] : 0));
                continue;
            }
            /* « bri »:0 éteint WLED sans changer la luminosité retenue. */
            if ($key === 'bri' && is_numeric($value) && (int) $value === 0) {
                if (!empty($_state['on'])) {
                    $out[] = 'on = true ' . __('au lieu de', __FILE__) . ' false';
                }
                continue;
            }
            /* Un « ps » qui désigne une playlist la lance : l'état porte alors
             * son numéro dans « pl », et dans « ps » celui du preset joué. */
            if ($key === 'ps' && is_numeric($value) && isset($_state['pl']) && (int) $_state['pl'] === (int) $value) {
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
        return array($_label . ' = ' . self::showValue($_actual) . ' ' . __('au lieu de', __FILE__) . ' ' . self::showValue($_sent));
    }

    /* Une valeur relue sur l'appareil, pour un message : elle finit dans une
     * commande info et dans le centre de messages, jamais sous forme de
     * balisage. */
    public static function showValue($_value) {
        $text = json_encode($_value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return mb_substr((string) $text, 0, 80);
    }

    /* Une valeur relative ne se compare pas à l'état relu : « ~10 »,
     * « ~-10 », « w~10 » (avec retour au début), « 1~5~ » (preset suivant
     * dans une plage), « t » pour basculer, « r » pour aléatoire, « !… ». */
    private static function isAbsolute($_value) {
        if (!is_string($_value)) {
            return true;
        }
        $v = trim($_value);
        return !($v === 't' || $v === 'r' || strpos($v, '~') !== false || strpos($v, '!') === 0);
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
    public static function segMismatches($_sent, $_segs, $_mainseg = 0) {
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
                    $selected[] = isset($segs[$_mainseg]) ? $_mainseg : (int) key($segs);
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
                /* Comme au niveau général, « bri »:0 éteint le segment sans
                 * changer sa luminosité retenue. */
                if ($key === 'bri' && is_numeric($value) && (int) $value === 0) {
                    if (!empty($segs[$id]['on'])) {
                        $out[] = 'seg ' . $id . '.on = true ' . __('au lieu de', __FILE__) . ' false';
                    }
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
            try {
                $have = isset($_actual[$slot]) ? (is_string($_actual[$slot]) ? self::hexToColor($_actual[$slot]) : array_values((array) $_actual[$slot])) : array();
            } catch (Throwable $e) {
                $have = array();
            }
            $n = min(count($wanted), count($have));
            $same = $n > 0;
            for ($i = 0; $i < $n; $i++) {
                $same = $same && (int) $wanted[$i] === (int) $have[$i];
            }
            if (!$same) {
                $out[] = 'seg ' . $_id . '.col[' . $slot . '] = ' . self::showValue($have) . ' ' . __('au lieu de', __FILE__) . ' ' . self::showValue($wanted);
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

    /* ========================================================= SCÈNES : BIBLIOTHÈQUE */

    /*
     * Une scène, c'est ce que WLED ne sait pas faire seul : un effet choisi
     * pour une situation (alarme, police, sonnette…), joué pendant une durée
     * donnée, avec une priorité, puis l'éclairage d'avant rendu tel quel.
     *
     * La bibliothèque est commune à tous les WLED : « Police » est la même
     * scène sur la bande du salon et sur la matrice du bureau. Chaque scène
     * porte une recette pour bande et, facultativement, une recette pour
     * matrice, qui peut y afficher un texte défilant. Les effets et palettes
     * y sont désignés par leur nom, retrouvé sur chaque appareil au moment de
     * jouer : un nom survit aux mises à jour de WLED, un numéro non.
     */
    const DEFAULT_SCENES = array(
        array('id' => 'alarme', 'name' => 'Alarme intrusion', 'priority' => 100, 'duration' => 300, 'end' => 'restore', 'guard' => 1,
              'strip' => array('effect' => 'Strobe Mega', 'colors' => array('#ff0000', '#ffffff', '#000000'), 'brightness' => 100, 'speed' => 200, 'intensity' => 128),
              'matrix' => array('enabled' => 1, 'effect' => 'Scrolling Text', 'colors' => array('#ff0000', '#000000', '#000000'), 'brightness' => 100, 'speed' => 200, 'intensity' => 128, 'text' => 'ALARME')),
        array('id' => 'incendie', 'name' => 'Incendie', 'priority' => 100, 'duration' => 300, 'end' => 'restore', 'guard' => 1,
              'strip' => array('effect' => 'Strobe', 'colors' => array('#ff3000', '#000000', '#000000'), 'brightness' => 100, 'speed' => 220, 'intensity' => 128),
              'matrix' => array('enabled' => 1, 'effect' => 'Scrolling Text', 'colors' => array('#ff3000', '#000000', '#000000'), 'brightness' => 100, 'speed' => 200, 'intensity' => 128, 'text' => 'FEU')),
        array('id' => 'police', 'name' => 'Police', 'priority' => 90, 'duration' => 120, 'end' => 'restore', 'guard' => 0,
              'strip' => array('effect' => 'Chase 2', 'colors' => array('#ff0000', '#0000ff', '#000000'), 'brightness' => 100, 'speed' => 230, 'intensity' => 128)),
        array('id' => 'fuite', 'name' => 'Fuite d\'eau', 'priority' => 80, 'duration' => 300, 'end' => 'restore', 'guard' => 1,
              'strip' => array('effect' => 'Running', 'colors' => array('#0040ff', '#000000', '#000000'), 'brightness' => 100, 'speed' => 200, 'intensity' => 128),
              'matrix' => array('enabled' => 1, 'effect' => 'Scrolling Text', 'colors' => array('#0040ff', '#000000', '#000000'), 'brightness' => 100, 'speed' => 200, 'intensity' => 128, 'text' => 'FUITE')),
        array('id' => 'sonnette', 'name' => 'Sonnette', 'priority' => 40, 'duration' => 10, 'end' => 'restore', 'guard' => 0,
              'strip' => array('effect' => 'Blink', 'colors' => array('#ffffff', '#000000', '#000000'), 'brightness' => 100, 'speed' => 230, 'intensity' => 128)),
        array('id' => 'notification', 'name' => 'Notification', 'priority' => 20, 'duration' => 15, 'end' => 'restore', 'guard' => 0,
              'strip' => array('effect' => 'Breathe', 'colors' => array('#00a0ff', '#000000', '#000000'), 'brightness' => 80, 'speed' => 128, 'intensity' => 128)),
    );

    const END_MODES = array('restore', 'off', 'keep');

    /* La bibliothèque, telle qu'enregistrée, ou celle livrée avec le plugin
     * tant que l'utilisateur n'y a pas touché. */
    public static function scenes() {
        $scenes = config::byKey('scenes', __CLASS__, '');
        if (is_string($scenes) && $scenes !== '') {
            $scenes = json_decode($scenes, true);
        }
        /* Une liste enregistrée vide reste vide : l'utilisateur a supprimé
         * toutes les scènes, elles ne doivent pas revenir sans leurs
         * commandes. */
        if (!is_array($scenes)) {
            $scenes = self::DEFAULT_SCENES;
        }
        return array_values(array_map(array(__CLASS__, 'normalizeScene'), $scenes));
    }

    /* Un nombre saisi dans l'éditeur : un champ vidé vaut « non renseigné »,
     * pas zéro — une durée vidée ne doit pas devenir une scène sans fin. */
    private static function intField($_array, $_key, $_default, $_min, $_max) {
        if (!isset($_array[$_key]) || $_array[$_key] === '' || !is_numeric($_array[$_key])) {
            return $_default;
        }
        return max($_min, min($_max, (int) $_array[$_key]));
    }

    /* Texte défilant : WLED garde au plus 32 octets de nom de segment sur un
     * ESP8266. Au-delà il le tronque, la vérification ne concorderait
     * jamais, et une coupure au milieu d'un caractère accentué rendrait son
     * JSON illisible. mb_strcut coupe en octets, sur une frontière de
     * caractère. */
    const SEGMENT_NAME_BYTES = 32;

    public static function normalizeRecipe($_recipe, $_isMatrix) {
        $r = is_array($_recipe) ? $_recipe : array();
        $colors = array();
        foreach (array(0, 1, 2) as $i) {
            $c = isset($r['colors'][$i]) ? strtolower(trim((string) $r['colors'][$i])) : '';
            $colors[] = preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : ($i === 0 ? '#ffffff' : '#000000');
        }
        $recipe = array(
            'effect'     => isset($r['effect']) ? trim((string) $r['effect']) : 'Solid',
            'colors'     => $colors,
            'palette'    => isset($r['palette']) ? trim((string) $r['palette']) : '',
            'brightness' => self::intField($r, 'brightness', 100, 1, 100),
            'speed'      => self::intField($r, 'speed', 128, 0, 255),
            'intensity'  => self::intField($r, 'intensity', 128, 0, 255),
            'text'       => isset($r['text']) ? trim(mb_strcut(trim(strip_tags((string) $r['text'])), 0, self::SEGMENT_NAME_BYTES, 'UTF-8')) : '',
            'json'       => isset($r['json']) ? trim((string) $r['json']) : '',
        );
        if ($recipe['effect'] === '') {
            $recipe['effect'] = 'Solid';
        }
        if ($_isMatrix) {
            $recipe = array('enabled' => !empty($r['enabled']) ? 1 : 0) + $recipe;
        }
        return $recipe;
    }

    public static function normalizeScene($_scene) {
        $s = is_array($_scene) ? $_scene : array();
        $name = isset($s['name']) ? self::deviceText($s['name'], 48) : '';
        $id = isset($s['id']) && is_scalar($s['id']) ? substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $s['id'])), 0, 32) : '';
        if ($id === '') {
            $id = self::slug($name !== '' ? $name : 'scene');
        }
        $end = isset($s['end']) && in_array($s['end'], self::END_MODES, true) ? $s['end'] : 'restore';
        return array(
            'id'       => $id,
            'name'     => $name !== '' ? $name : $id,
            'priority' => self::intField($s, 'priority', 50, 0, 100),
            'duration' => self::intField($s, 'duration', 60, 0, 86400),
            'end'      => $end,
            'guard'    => !empty($s['guard']) ? 1 : 0,
            'strip'    => self::normalizeRecipe(isset($s['strip']) ? $s['strip'] : array(), false),
            'matrix'   => self::normalizeRecipe(isset($s['matrix']) ? $s['matrix'] : array(), true),
        );
    }

    public static function slug($_text) {
        $text = mb_strtolower(trim((string) $_text), 'UTF-8');
        $text = strtr($text, array('à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                                   'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c'));
        $text = trim(preg_replace('/[^a-z0-9]+/', '_', $text), '_');
        return $text !== '' ? substr($text, 0, 32) : 'scene';
    }

    /*
     * Enregistre la bibliothèque. Les identifiants restent stables d'un
     * enregistrement à l'autre : ils nomment les commandes « Scène … » des
     * équipements, que des scénarios appellent. Deux scènes ne peuvent porter
     * ni le même identifiant ni le même nom.
     */
    public static function saveScenes($_scenes) {
        if (!is_array($_scenes)) {
            throw new Exception(__('Liste de scènes illisible.', __FILE__));
        }
        $out = array();
        $ids = array();
        $names = array();
        foreach ($_scenes as $raw) {
            $scene = self::normalizeScene($raw);
            $base = $scene['id'];
            for ($n = 2; isset($ids[$scene['id']]); $n++) {
                $scene['id'] = $base . '_' . $n;
            }
            if (isset($names[mb_strtolower($scene['name'])])) {
                throw new Exception(sprintf(__('Deux scènes portent le nom « %s ».', __FILE__), $scene['name']));
            }
            if ($scene['strip']['json'] !== '' && !is_array(json_decode($scene['strip']['json'], true))) {
                throw new Exception(sprintf(__('Scène « %s » : le JSON avancé (bande) est illisible.', __FILE__), $scene['name']));
            }
            if ($scene['matrix']['json'] !== '' && !is_array(json_decode($scene['matrix']['json'], true))) {
                throw new Exception(sprintf(__('Scène « %s » : le JSON avancé (matrice) est illisible.', __FILE__), $scene['name']));
            }
            $ids[$scene['id']] = 1;
            $names[mb_strtolower($scene['name'])] = 1;
            $out[] = $scene;
        }
        config::save('scenes', json_encode($out), __CLASS__);
        foreach (self::byType(__CLASS__) as $eqLogic) {
            try {
                $eqLogic->syncSceneCommands($out);
                $eqLogic->purgeScenes($ids);
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
        return $out;
    }

    /* Une scène par son identifiant ou par son nom, sans égard à la casse. */
    public static function findScene($_ref) {
        $ref = mb_strtolower(trim((string) $_ref));
        foreach (self::scenes() as $scene) {
            if ($scene['id'] === $ref || mb_strtolower($scene['name']) === $ref) {
                return $scene;
            }
        }
        $names = array_map(function ($s) { return $s['name']; }, self::scenes());
        throw new Exception(sprintf(__('Scène inconnue : « %s ». Scènes disponibles : %s', __FILE__), $_ref, implode(', ', $names)));
    }

    /*
     * Options d'un lancement, écrites en clair dans le message d'un scénario :
     *   « durée=30 »   « durée=5m »   « durée=0 » (sans fin)   ou « 30 » seul
     *   « délai=10 »   « heure=22:30 »   « priorité=95 »   « fin=éteindre »
     * Les unités s, m, h sont comprises ; sans unité, ce sont des secondes.
     */
    public static function parseSceneOptions($_text, $_now = null, $_textOptions = false) {
        $now = $_now === null ? time() : (int) $_now;
        $options = array();
        $text = trim((string) $_text);
        if ($text === '') {
            return $options;
        }
        /* « durée=5 min » s'écrit naturellement avec une espace. */
        $text = preg_replace('/(\d)\s+(s|sec|min|m|h)\b/iu', '$1$2', $text);
        foreach (preg_split('/[\s;]+/', $text) as $token) {
            if ($token === '') {
                continue;
            }
            $parts = explode('=', $token, 2);
            if (count($parts) === 1) {
                $key = 'duree';
                $value = $parts[0];
            } else {
                $key = self::slug($parts[0]);
                $value = trim($parts[1]);
            }
            switch ($key) {
                case 'duree':
                case 'duration':
                case 'd':
                    $options['duration'] = self::parseSeconds($value);
                    break;
                case 'delai':
                case 'delay':
                case 'dans':
                    $options['delay'] = self::parseSeconds($value);
                    break;
                case 'heure':
                case 'at':
                case 'a':
                    if (!preg_match('/^(\d{1,2})[:hH](\d{2})$/', $value, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
                        throw new Exception(__('Heure illisible :', __FILE__) . ' ' . $value);
                    }
                    $at = mktime((int) $m[1], (int) $m[2], 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
                    if ($at <= $now) {
                        $at = strtotime('+1 day', $at);
                    }
                    $options['delay'] = $at - $now;
                    break;
                case 'priorite':
                case 'prio':
                case 'priority':
                    if (!is_numeric($value)) {
                        throw new Exception(__('Priorité illisible :', __FILE__) . ' ' . $value);
                    }
                    $options['priority'] = max(0, min(100, (int) $value));
                    break;
                case 'fin':
                case 'end':
                    $map = array('restaurer' => 'restore', 'restore' => 'restore', 'eteindre' => 'off', 'off' => 'off',
                                 'garder' => 'keep', 'keep' => 'keep', 'laisser' => 'keep');
                    $v = self::slug($value);
                    if (!isset($map[$v])) {
                        throw new Exception(__('Fin de scène illisible (restaurer, éteindre ou garder) :', __FILE__) . ' ' . $value);
                    }
                    $options['end'] = $map[$v];
                    break;
                case 'couleur':
                case 'color':
                    if (!$_textOptions) {
                        throw new Exception(__('Option de scène inconnue :', __FILE__) . ' ' . $token);
                    }
                    $options['color'] = self::parseColor($value);
                    break;
                case 'vitesse':
                case 'speed':
                    if (!$_textOptions || !is_numeric($value)) {
                        throw new Exception(__('Option de scène inconnue ou illisible :', __FILE__) . ' ' . $token);
                    }
                    $options['speed'] = max(0, min(255, (int) $value));
                    break;
                default:
                    throw new Exception(__('Option de scène inconnue :', __FILE__) . ' ' . $token);
            }
        }
        return $options;
    }

    public static function parseSeconds($_value) {
        $v = mb_strtolower(trim((string) $_value), 'UTF-8');
        if (in_array($v, array('0', 'infini', 'sansfin', 'illimite', 'illimitee', 'none'), true)) {
            return 0;
        }
        if (!preg_match('/^(\d+(?:[.,]\d+)?)\s*(s|sec|m|min|h)?$/', $v, $m)) {
            throw new Exception(__('Durée illisible :', __FILE__) . ' ' . $_value);
        }
        $n = (float) str_replace(',', '.', $m[1]);
        $unit = isset($m[2]) ? $m[2] : 's';
        $factor = ($unit === 'h') ? 3600 : (($unit === 'm' || $unit === 'min') ? 60 : 1);
        return (int) round($n * $factor);
    }

    /* ========================================================= SCÈNES : ORDRES WLED */

    /* La recette qui convient à cet appareil. */
    public function recipeFor($_scene) {
        if ($this->isMatrix() && !empty($_scene['matrix']['enabled'])) {
            return $_scene['matrix'];
        }
        return $_scene['strip'];
    }

    /*
     * L'ordre WLED qui joue une scène sur cet appareil. Il vise tous les
     * segments existants (relevés dans $_state), pour que la scène couvre
     * toute la bande quelle que soit la sélection faite dans WLED, et se fait
     * sans fondu (« tt »:0), pour qu'un flash d'alarme parte net.
     */
    public function sceneFragment($_scene, $_state) {
        $recipe = $this->recipeFor($_scene);
        $fx = is_numeric($recipe['effect']) ? (int) $recipe['effect'] : $this->effectIdByName($recipe['effect']);
        if ($fx === null) {
            throw new Exception(sprintf(__('Scène « %s » : effet « %s » inconnu sur ce WLED (version %s).', __FILE__),
                $_scene['name'], $recipe['effect'], $this->getConfiguration('version')));
        }
        $seg = array('on' => true, 'frz' => false, 'fx' => $fx, 'sx' => $recipe['speed'], 'ix' => $recipe['intensity'],
                     'col' => array_map(array(__CLASS__, 'hexToColor'), $recipe['colors']));
        if ($recipe['palette'] !== '') {
            $pal = is_numeric($recipe['palette']) ? (int) $recipe['palette'] : $this->paletteIdByName($recipe['palette']);
            if ($pal === null) {
                throw new Exception(sprintf(__('Scène « %s » : palette « %s » inconnue sur ce WLED.', __FILE__), $_scene['name'], $recipe['palette']));
            }
            $seg['pal'] = $pal;
        }
        if ($recipe['text'] !== '') {
            /* L'effet « Scrolling Text » affiche le nom du segment. */
            $seg['n'] = $recipe['text'];
        }
        $fragment = array('on' => true, 'bri' => max(1, (int) round($recipe['brightness'] * 2.55)), 'tt' => 0);

        $extra = $recipe['json'] !== '' ? json_decode($recipe['json'], true) : array();
        if (!is_array($extra)) {
            $extra = array();
        }
        if (isset($extra['seg']) && is_array($extra['seg']) && array_keys($extra['seg']) !== range(0, count($extra['seg']) - 1)) {
            $seg = array_replace($seg, $extra['seg']);
            unset($extra['seg']);
        }

        $ids = array();
        foreach ((isset($_state['seg']) && is_array($_state['seg'])) ? $_state['seg'] : array() as $rank => $s) {
            $ids[] = isset($s['id']) ? (int) $s['id'] : (int) $rank;
        }
        if (empty($ids)) {
            $ids = array(0);
        }
        $fragment['seg'] = array();
        foreach ($ids as $id) {
            $fragment['seg'][] = array('id' => $id) + $seg;
        }
        return array_replace($fragment, $extra);
    }

    public function paletteIdByName($_name) {
        $wanted = strtolower(trim((string) $_name));
        foreach ((array) $this->getCache('pal_names', array()) as $id => $name) {
            if (strtolower(trim((string) $name)) === $wanted) {
                return (int) $id;
            }
        }
        return null;
    }

    /* Réglages d'un segment que rend la restauration : l'aspect, jamais la
     * géométrie, qu'une scène ne touche pas. */
    const RESTORED_SEG_KEYS = array('on', 'bri', 'col', 'fx', 'sx', 'ix', 'pal', 'c1', 'c2', 'c3', 'o1', 'o2', 'o3', 'frz', 'cct', 'm12', 'si');

    /*
     * L'ordre qui rend l'éclairage d'avant la première scène. Une playlist en
     * cours est relancée plutôt que figée sur le preset qu'elle jouait.
     */
    public static function restoreFragment($_snapshot) {
        $snap = is_array($_snapshot) ? $_snapshot : array();
        if (isset($snap['pl']) && (int) $snap['pl'] > 0) {
            return array('ps' => (int) $snap['pl']);
        }
        $fragment = array();
        foreach (array('on', 'bri', 'lor') as $key) {
            if (isset($snap[$key])) {
                $fragment[$key] = $snap[$key];
            }
        }
        $segs = array();
        foreach ((isset($snap['seg']) && is_array($snap['seg'])) ? $snap['seg'] : array() as $rank => $s) {
            if (!is_array($s)) {
                continue;
            }
            $seg = array('id' => isset($s['id']) ? (int) $s['id'] : (int) $rank);
            foreach (self::RESTORED_SEG_KEYS as $key) {
                if (array_key_exists($key, $s)) {
                    $seg[$key] = $s[$key];
                }
            }
            /* WLED omet le nom d'un segment qui n'en a pas : la scène a pu en
             * poser un (texte défilant), il faut l'effacer. */
            $seg['n'] = isset($s['n']) ? (string) $s['n'] : '';
            $segs[] = $seg;
        }
        if (!empty($segs)) {
            $fragment['seg'] = $segs;
        }
        return $fragment;
    }

    /* ========================================================= SCÈNES : PILE */

    /*
     * Chaque appareil tient une pile de scènes : une entrée par scène lancée,
     * active ou en attente de son heure. Celle qui s'affiche est la plus
     * prioritaire des actives, la plus récente à priorité égale. Quand elle
     * se termine, la suivante reprend la main ; quand il n'y en a plus,
     * l'éclairage d'avant la première scène est rendu.
     *
     * Chaque entrée emporte la définition de sa scène au moment du lancement :
     * modifier la bibliothèque ne change pas une scène déjà en cours.
     *
     * Cet état vit sous sa propre clé de cache, et non dans le cache de
     * l'équipement : celui-ci est récrit en entier à chaque setCache(), sans
     * verrou, par le relevé de chaque minute, qui écraserait sinon une pile
     * modifiée au même instant. Il n'est modifié que sous un verrou de
     * fichier : un scénario d'alarme et le réveil du démon peuvent arriver en
     * même temps.
     */

    /* Après ce délai sans réussir à rendre l'éclairage, on abandonne : une
     * restauration tardive écraserait ce que l'utilisateur a fait depuis. */
    const RESTORE_GIVE_UP = 1800;

    /* Verrous tenus par ce processus : un scénario synchrone déclenché par une
     * mise à jour de commande pendant qu'on tient le verrou peut rappeler le
     * même équipement, et un second flock() sur le même fichier depuis le
     * même processus attendrait indéfiniment. */
    private static $_locks = array();

    private function sceneKey() {
        return 'wledbe::scene::' . (int) $this->getId();
    }

    public function sceneState() {
        $state = cache::byKey($this->sceneKey())->getValue(array());
        return is_array($state) ? $state : array();
    }

    private function sceneGet($_key, $_default = null) {
        $state = $this->sceneState();
        return array_key_exists($_key, $state) ? $state[$_key] : $_default;
    }

    /* Écrit plusieurs clés d'un coup ; null efface la clé. */
    private function sceneSet($_values) {
        $state = $this->sceneState();
        foreach ($_values as $key => $value) {
            if ($value === null) {
                unset($state[$key]);
            } else {
                $state[$key] = $value;
            }
        }
        cache::set($this->sceneKey(), $state);
    }

    /* $_wait faux : rend null sans attendre si le verrou est pris. */
    private function withLock($_callback, $_wait = true) {
        $id = (int) $this->getId();
        if (!empty(self::$_locks[$id])) {
            return $_callback();
        }
        $file = jeedom::getTmpFolder(__CLASS__) . '/stack_' . $id . '.lock';
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            log::add(__CLASS__, 'warning', $this->getHumanName() . ' : ' . __('verrou des scènes impossible à ouvrir :', __FILE__) . ' ' . $file);
            return $_callback();
        }
        if (!flock($handle, $_wait ? LOCK_EX : (LOCK_EX | LOCK_NB))) {
            fclose($handle);
            return null;
        }
        self::$_locks[$id] = true;
        try {
            return $_callback();
        } finally {
            unset(self::$_locks[$id]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function stack() {
        $stack = $this->sceneGet('stack', array());
        return is_array($stack) ? $stack : array();
    }

    private static function hasActive($_stack) {
        foreach ($_stack as $entry) {
            if (!empty($entry['active'])) {
                return true;
            }
        }
        return false;
    }

    /* L'entrée à afficher : la plus prioritaire des actives, la plus récente
     * à priorité égale. */
    public static function topEntry($_stack) {
        $top = null;
        foreach ($_stack as $entry) {
            if (empty($entry['active'])) {
                continue;
            }
            if ($top === null || $entry['priority'] > $top['priority']
                || ($entry['priority'] == $top['priority'] && $entry['seq'] > $top['seq'])) {
                $top = $entry;
            }
        }
        return $top;
    }

    /* Lance une scène de la bibliothèque sur cet appareil, tout de suite ou
     * après un délai. */
    public function startScene($_ref, $_options = array()) {
        return $this->playScene(self::findScene($_ref), $_options);
    }

    /* Lance une définition de scène, enregistrée ou non (essai depuis
     * l'éditeur). */
    public function playScene($_scene, $_options = array()) {
        $scene = self::normalizeScene($_scene);
        return $this->withLock(function () use ($scene, $_options) {
            $now = time();
            $seq = (int) $this->sceneGet('seq', 0) + 1;
            $entry = array(
                'key'      => $scene['id'],
                'scene'    => $scene['id'],
                'name'     => $scene['name'],
                'def'      => $scene,
                'test'     => !empty($_options['test']),
                'priority' => isset($_options['priority']) ? (int) $_options['priority'] : $scene['priority'],
                'duration' => isset($_options['duration']) ? (int) $_options['duration'] : $scene['duration'],
                'end'      => isset($_options['end']) ? $_options['end'] : $scene['end'],
                'guard'    => $scene['guard'],
                'seq'      => $seq,
                'active'   => false,
                'start_at' => $now + (isset($_options['delay']) ? max(0, (int) $_options['delay']) : 0),
                'until'    => 0,
            );
            /* Relancer une scène déjà présente la remplace : sa durée repart
             * de zéro, elle ne s'empile pas deux fois. */
            $stack = array_values(array_filter($this->stack(), function ($e) use ($entry) { return $e['key'] !== $entry['key']; }));
            $stack[] = $entry;
            $this->sceneSet(array('seq' => $seq, 'stack' => $stack));
            if ($entry['start_at'] > $now) {
                log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . sprintf(__('scène « %s » programmée à %s', __FILE__), $entry['name'], date('H:i:s', $entry['start_at'])));
                /* Si l'entrée remplacée était affichée, l'appareil doit passer
                 * à la suivante (ou revenir à l'éclairage) pendant l'attente. */
                $this->showTop(null);
                return $entry;
            }
            $this->activateDue($now, $entry['key']);
            return $entry;
        });
    }

    /*
     * Fait avancer la pile : les scènes dont l'heure est venue deviennent
     * actives, celles dont la durée est écoulée sont retirées, et l'appareil
     * affiche la bonne. Appelée sous verrou.
     */
    private function activateDue($_now, $_forceKey = null) {
        $stack = $this->stack();
        $applied = (string) $this->sceneGet('applied_key', '');
        foreach ($stack as $i => $entry) {
            if (empty($entry['active']) && $entry['start_at'] <= $_now) {
                $stack[$i]['active'] = true;
                $stack[$i]['until'] = $entry['duration'] > 0 ? $_now + $entry['duration'] : 0;
                log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . sprintf(__('scène « %s » lancée (priorité %d, %s)', __FILE__),
                    $entry['name'], $entry['priority'], $entry['duration'] > 0 ? $entry['duration'] . ' s' : __('sans fin', __FILE__)));
            }
        }
        /* Si plusieurs scènes finissent ensemble, c'est le mode de fin de
         * celle qui était affichée qui compte, à défaut la plus prioritaire. */
        $ended = null;
        foreach ($stack as $i => $entry) {
            if (!empty($entry['active']) && $entry['until'] > 0 && $entry['until'] <= $_now) {
                log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . sprintf(__('scène « %s » terminée', __FILE__), $entry['name']));
                if ($ended === null || $entry['key'] === $applied
                    || ($ended['key'] !== $applied && $entry['priority'] > $ended['priority'])) {
                    $ended = $entry;
                }
                unset($stack[$i]);
            }
        }
        $this->sceneSet(array('stack' => array_values($stack)));
        $this->showTop($ended, $_forceKey);
    }

    /*
     * Met l'appareil en accord avec le sommet de la pile. $_ended est la
     * dernière entrée retirée : c'est son mode de fin qui s'applique quand la
     * pile se vide. $_forceKey : une scène qu'on vient de relancer, à rejouer
     * même si elle était déjà affichée.
     *
     * Une scène injouable (effet absent de ce WLED…) est retirée de la pile
     * et la suivante essayée ; l'erreur est rendue à l'appelant à la fin. Un
     * appareil muet laisse la pile en l'état : tick() réessaiera.
     */
    private function showTop($_ended, $_forceKey = null) {
        $error = null;
        try {
            for ($guard = 0; $guard < 20; $guard++) {
                $top = self::topEntry($this->stack());
                $applied = (string) $this->sceneGet('applied_key', '');
                if ($top === null) {
                    /* Rien à rendre si aucune scène n'a jamais été affichée. */
                    if ($applied !== '' || is_array($this->sceneGet('snapshot', null))) {
                        $this->endScenes($_ended !== null ? $_ended['end'] : 'restore');
                    }
                    break;
                }
                if ($top['key'] === $applied && $top['key'] !== $_forceKey) {
                    break;
                }
                try {
                    $this->applyEntry($top);
                    break;
                } catch (wledbeSceneError $e) {
                    $error = $e;
                    $this->dropEntry($top, $e->getMessage());
                    $_ended = $top;
                    $_forceKey = null;
                }
            }
        } finally {
            $this->publishScene();
            self::notifyDaemon();
        }
        if ($error !== null) {
            throw $error;
        }
    }

    private function dropEntry($_entry, $_reason) {
        $stack = array_values(array_filter($this->stack(), function ($e) use ($_entry) { return $e['key'] !== $_entry['key']; }));
        $this->sceneSet(array('stack' => $stack));
        log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $_reason);
        message::add(__CLASS__, $this->getHumanName() . ' : ' . $_reason, '', 'scene' . $this->getId());
    }

    private function applyEntry($_entry) {
        $scene = isset($_entry['def']) && is_array($_entry['def']) ? $_entry['def'] : null;
        if ($scene === null) {
            try {
                $scene = self::findScene($_entry['scene']);
            } catch (Throwable $e) {
                throw new wledbeSceneError($e->getMessage());
            }
        }
        /* L'éclairage d'avant n'est photographié qu'une fois, à l'entrée dans
         * la première scène : une scène qui en remplace une autre ne doit pas
         * prendre la précédente pour l'état à rendre. Une restauration encore
         * en souffrance garde aussi sa photographie : c'est elle, et non la
         * scène restée affichée, qu'il faudra rendre. */
        $snapshot = $this->sceneGet('snapshot', null);
        if (!is_array($snapshot) || empty($snapshot)) {
            try {
                $snapshot = $this->call('GET', '/json/state');
            } catch (Throwable $e) {
                $this->sceneSet(array('retry_at' => time() + self::RESTORE_RETRY));
                throw $e;
            }
            $this->sceneSet(array('snapshot' => $snapshot));
        }
        try {
            $fragment = $this->sceneFragment($scene, $snapshot);
        } catch (Throwable $e) {
            throw new wledbeSceneError($e->getMessage());
        }
        $this->sceneSet(array('pending_restore' => null, 'restore_at' => null, 'restore_since' => null));
        try {
            $this->sendState($fragment);
        } catch (Throwable $e) {
            $this->sceneSet(array('applied_key' => null, 'applied_fragment' => null, 'retry_at' => time() + self::RESTORE_RETRY));
            throw $e;
        }
        $this->sceneSet(array('applied_key' => $_entry['key'], 'applied_fragment' => $fragment,
                              'guard_at' => time() + self::GUARD_EVERY, 'retry_at' => null));
    }

    /* La pile est vide : on rend l'éclairage, on éteint, ou on laisse. */
    private function endScenes($_mode) {
        $snapshot = $this->sceneGet('snapshot', null);
        $this->sceneSet(array('applied_key' => null, 'applied_fragment' => null, 'guard_at' => null, 'retry_at' => null));
        if ($_mode === 'keep') {
            $this->sceneSet(array('snapshot' => null));
            return;
        }
        $fragment = ($_mode === 'off' || !is_array($snapshot) || empty($snapshot))
            ? array('on' => false) : self::restoreFragment($snapshot);
        try {
            $this->sendState($fragment);
            $this->sceneSet(array('snapshot' => null, 'pending_restore' => null, 'restore_at' => null, 'restore_since' => null));
        } catch (Throwable $e) {
            /* L'appareil ne répond pas : la restauration est retentée aux
             * réveils suivants, plutôt que de laisser l'alarme allumée. La
             * photographie est gardée pour une scène qui arriverait entre
             * temps. */
            $this->sceneSet(array('pending_restore' => $fragment, 'restore_at' => time() + self::RESTORE_RETRY,
                                  'restore_since' => (int) $this->sceneGet('restore_since', time())));
            log::add(__CLASS__, 'warning', $this->getHumanName() . ' : ' . __('restauration impossible, nouvel essai dans', __FILE__) . ' ' . self::RESTORE_RETRY . ' s');
        }
    }

    /* Arrête la scène affichée ($_all faux) ou toutes les scènes, y compris
     * celles programmées. */
    public function stopScenes($_all) {
        return $this->withLock(function () use ($_all) {
            $stack = $this->stack();
            $top = self::topEntry($stack);
            if ($_all) {
                if (empty($stack) && (string) $this->sceneGet('applied_key', '') === '') {
                    return false;
                }
                $ended = $top !== null ? $top : array('end' => 'restore', 'name' => '');
                /* « Tout arrêter » rend toujours l'éclairage d'avant. */
                $ended['end'] = 'restore';
                $stack = array();
            } else {
                if ($top === null) {
                    return false;
                }
                $ended = $top;
                $stack = array_values(array_filter($stack, function ($e) use ($top) { return $e['key'] !== $top['key']; }));
            }
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . ($_all ? __('toutes les scènes arrêtées', __FILE__)
                : sprintf(__('scène « %s » arrêtée', __FILE__), $ended['name'])));
            $this->sceneSet(array('stack' => $stack));
            $this->showTop($ended);
            return true;
        });
    }

    /* Retire des piles les scènes supprimées de la bibliothèque. Une scène en
     * cours d'essai, jamais enregistrée, n'y figure pas : on la laisse finir. */
    public function purgeScenes($_ids) {
        $this->withLock(function () use ($_ids) {
            $stack = $this->stack();
            $kept = array_values(array_filter($stack, function ($e) use ($_ids) { return !empty($e['test']) || isset($_ids[$e['scene']]); }));
            if (count($kept) === count($stack)) {
                return;
            }
            $this->sceneSet(array('stack' => $kept));
            try {
                $this->showTop(null);
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $e->getMessage());
            }
        });
    }

    /*
     * Une commande manuelle (allumer, couleur, effet…) pendant une scène :
     * l'utilisateur reprend la main. Les scènes affichées ou recouvertes sont
     * abandonnées sans rien restaurer — éteindre la lampe pendant la sonnette
     * doit la laisser éteinte — et une restauration en souffrance aussi. Les
     * scènes programmées pour plus tard restent prévues.
     */
    private function abandonScenes() {
        $this->withLock(function () {
            $stack = $this->stack();
            $pending = $this->sceneGet('pending_restore', null);
            if (!self::hasActive($stack) && (string) $this->sceneGet('applied_key', '') === '' && empty($pending)) {
                return;
            }
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('commande manuelle, scènes abandonnées', __FILE__));
            $later = array_values(array_filter($stack, function ($e) { return empty($e['active']); }));
            $this->sceneSet(array('stack' => $later, 'applied_key' => null, 'applied_fragment' => null, 'snapshot' => null,
                                  'pending_restore' => null, 'restore_at' => null, 'restore_since' => null,
                                  'guard_at' => null, 'retry_at' => null));
            $this->publishScene();
            self::notifyDaemon();
        });
    }

    /*
     * Réveil par le démon (ou le cron) : avance la pile, retente ce qui a
     * échoué, et monte la garde. Si un autre processus tient déjà le verrou
     * (un scénario qui lance une scène sur un WLED lent), on n'attend pas : il
     * a la main, et le prochain réveil viendra.
     */
    public function tick() {
        return $this->withLock(function () {
            $now = time();
            $stack = $this->stack();

            /* Restauration en souffrance, s'il n'y a plus rien d'affiché. */
            $pending = $this->sceneGet('pending_restore', null);
            if (is_array($pending) && !empty($pending) && !self::hasActive($stack)
                && (int) $this->sceneGet('restore_at', 0) <= $now) {
                try {
                    $this->sendState($pending);
                    $this->sceneSet(array('snapshot' => null, 'pending_restore' => null, 'restore_at' => null, 'restore_since' => null));
                    log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('éclairage restauré', __FILE__));
                } catch (Throwable $e) {
                    if ($now - (int) $this->sceneGet('restore_since', $now) > self::RESTORE_GIVE_UP) {
                        $this->sceneSet(array('snapshot' => null, 'pending_restore' => null, 'restore_at' => null, 'restore_since' => null));
                        $text = __('éclairage non restauré : le WLED ne répond plus depuis trop longtemps.', __FILE__);
                        log::add(__CLASS__, 'warning', $this->getHumanName() . ' : ' . $text);
                        message::add(__CLASS__, $this->getHumanName() . ' : ' . $text, '', 'restore' . $this->getId());
                    } else {
                        $this->sceneSet(array('restore_at' => $now + self::RESTORE_RETRY));
                    }
                }
            }

            try {
                $this->activateDue($now);
                /* Une scène qui n'a pas pu être affichée (appareil muet) est
                 * réessayée. */
                $top = self::topEntry($this->stack());
                if ($top !== null && $top['key'] !== (string) $this->sceneGet('applied_key', '')
                    && (int) $this->sceneGet('retry_at', 0) <= $now) {
                    $this->showTop(null);
                }
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $e->getMessage());
            }

            /* La garde : une scène protégée qui a été défaite (bouton de
             * l'appareil, redémarrage, autre système) est réimposée. */
            $top = self::topEntry($this->stack());
            $fragment = $this->sceneGet('applied_fragment', null);
            if ($top !== null && !empty($top['guard']) && $top['key'] === (string) $this->sceneGet('applied_key', '')
                && is_array($fragment) && (int) $this->sceneGet('guard_at', 0) <= $now) {
                $this->sceneSet(array('guard_at' => $now + self::GUARD_EVERY, 'guard_ran' => $now));
                try {
                    $diff = self::mismatches($fragment, $this->call('GET', '/json/state'));
                    if (!empty($diff)) {
                        log::add(__CLASS__, 'warning', $this->getHumanName() . ' : ' . sprintf(__('scène « %s » défaite (%s), réimposée', __FILE__), $top['name'], implode(' ; ', $diff)));
                        $this->sendState($fragment);
                    }
                } catch (Throwable $e) {
                    log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . __('garde :', __FILE__) . ' ' . $e->getMessage());
                }
                self::notifyDaemon();
            }
            return true;
        }, false);
    }

    /* Prochain instant où tick() a quelque chose à faire, ou null. Chaque
     * cas porte sa propre échéance, toujours repoussée après un essai : un
     * réveil ne reste jamais dans le passé. */
    public function nextWake() {
        $stack = $this->stack();
        $now = time();
        $times = array();
        foreach ($stack as $entry) {
            if (empty($entry['active'])) {
                $times[] = (int) $entry['start_at'];
            } elseif ((int) $entry['until'] > 0) {
                $times[] = (int) $entry['until'];
            }
        }
        $top = self::topEntry($stack);
        $applied = (string) $this->sceneGet('applied_key', '');
        if ($top !== null && $top['key'] !== $applied) {
            $times[] = (int) $this->sceneGet('retry_at', $now);
        } elseif ($top !== null && !empty($top['guard'])) {
            $times[] = (int) $this->sceneGet('guard_at', $now);
        }
        $pending = $this->sceneGet('pending_restore', null);
        if (is_array($pending) && !empty($pending) && !self::hasActive($stack)) {
            $times[] = (int) $this->sceneGet('restore_at', $now);
        }
        return empty($times) ? null : min($times);
    }

    private function publishScene() {
        $top = self::topEntry($this->stack());
        $this->publishCmd('scene', $top !== null ? $top['name'] : '');
        $this->publishCmd('scene_active', $top !== null ? 1 : 0);
        $this->publishCmd('scene_until', $top === null ? '' : ((int) $top['until'] > 0 ? date('H:i:s', (int) $top['until']) : __('sans fin', __FILE__)));
    }

    /* ========================================================= TEXTE SUR MATRICE */

    /* Couleurs qu'un scénario peut nommer au lieu d'écrire #rrggbb. */
    const COLOR_NAMES = array(
        'rouge' => '#ff0000', 'vert' => '#00ff00', 'bleu' => '#0000ff', 'blanc' => '#ffffff',
        'jaune' => '#ffff00', 'orange' => '#ff8000', 'violet' => '#8000ff', 'rose' => '#ff40a0',
        'cyan' => '#00ffff', 'magenta' => '#ff00ff',
        'red' => '#ff0000', 'green' => '#00ff00', 'blue' => '#0000ff', 'white' => '#ffffff',
        'yellow' => '#ffff00', 'purple' => '#8000ff', 'pink' => '#ff40a0',
    );

    public static function parseColor($_value) {
        $v = self::slug($_value);
        if (isset(self::COLOR_NAMES[$v])) {
            return self::COLOR_NAMES[$v];
        }
        $hex = '#' . strtolower(ltrim(trim((string) $_value), '#'));
        if (!preg_match('/^#[0-9a-f]{6}$/', $hex)) {
            throw new Exception(__('Couleur illisible (nom comme « rouge », ou #rrggbb) :', __FILE__) . ' ' . $_value);
        }
        return $hex;
    }

    /*
     * Affiche un texte défilant sur une matrice, le temps voulu, puis rend
     * l'affichage d'avant. C'est une scène comme une autre, fabriquée pour
     * l'occasion : elle prend sa place dans la pile, avec sa priorité.
     *
     *   texte   : ce qui défile (32 octets au plus, la limite de WLED)
     *   options : couleur=rouge  durée=30  vitesse=200  priorité=60  fin=…
     *
     * Le texte peut porter les jetons de WLED : #HH:#MM pour l'heure, #DD.#MO
     * pour la date…
     */
    public function showText($_text, $_options = '') {
        if (!$this->isMatrix()) {
            throw new Exception(__('Le texte défilant ne s\'affiche que sur une matrice.', __FILE__));
        }
        $text = trim((string) $_text);
        if ($text === '') {
            throw new Exception(__('Aucun texte à afficher.', __FILE__));
        }
        $options = self::parseSceneOptions($_options, null, true);
        $color = isset($options['color']) ? $options['color'] : '#ffffff';
        $scene = array(
            'id' => 'texte', 'name' => __('Texte', __FILE__),
            'priority' => isset($options['priority']) ? $options['priority'] : 60,
            'duration' => isset($options['duration']) ? $options['duration'] : 30,
            'end' => isset($options['end']) ? $options['end'] : 'restore', 'guard' => 0,
            'strip' => array('effect' => 'Solid', 'colors' => array($color)),
            'matrix' => array('enabled' => 1, 'effect' => 'Scrolling Text', 'colors' => array($color, '#000000', '#000000'),
                              'brightness' => 100, 'speed' => isset($options['speed']) ? $options['speed'] : 128,
                              'intensity' => 128, 'text' => $text),
        );
        unset($options['color'], $options['speed']);
        return $this->playScene($scene, $options);
    }

    /* ================================================================ GROUPES */

    /*
     * Un groupe est un équipement du plugin sans appareil propre : il pilote
     * d'un seul ordre les WLED qu'on y a cochés — toute la maison en
     * « Police », toutes les bandes éteintes. Chaque membre garde sa propre
     * vérification et sa propre pile de scènes : un groupe n'est qu'une façon
     * d'adresser plusieurs appareils.
     *
     * Les commandes simples partent en parallèle, pour que les lampes
     * changent ensemble ; les scènes sont lancées membre par membre.
     */
    public function isGroup() {
        return $this->getConfiguration('kind', 'device') === 'group';
    }

    /* Identifiants des membres, lus dans la configuration (liste ou texte
     * « 12,34 » posté par la page). */
    public static function parseMembers($_value) {
        if (is_string($_value)) {
            $decoded = json_decode($_value, true);
            $_value = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $_value);
        }
        $ids = array();
        foreach ((array) $_value as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }
        return array_values($ids);
    }

    /* Les membres existants, actifs et configurés. */
    public function members() {
        $members = array();
        foreach (self::parseMembers($this->getConfiguration('members', array())) as $id) {
            $eqLogic = self::byId($id);
            if (is_object($eqLogic) && $eqLogic->getEqType_name() === __CLASS__ && !$eqLogic->isGroup()
                && $eqLogic->getIsEnable() && $eqLogic->isConfigured()) {
                $members[] = $eqLogic;
            }
        }
        return $members;
    }

    public static function createGroup($_name) {
        $name = trim((string) $_name);
        if ($name === '') {
            throw new Exception(__('Donnez un nom au groupe.', __FILE__));
        }
        $eqLogic = new self();
        $eqLogic->setEqType_name(__CLASS__);
        $eqLogic->setName($name);
        $eqLogic->setConfiguration('kind', 'group');
        $eqLogic->setConfiguration('members', array());
        $eqLogic->setIsEnable(1);
        $eqLogic->setIsVisible(1);
        $eqLogic->setCategory('light', 1);
        $eqLogic->save();
        return $eqLogic;
    }

    /*
     * Envoi en parallèle d'un ordre par membre. Premier envoi simultané, avec
     * « v »:true ; les membres dont l'état relu concorde sont réglés ; les
     * autres passent par sendState(), avec ses relances. Rend la liste des
     * échecs, par membre.
     */
    public static function sendStateMany($_orders) {
        $requests = array();
        foreach ($_orders as $id => $order) {
            $body = $order['fragment'];
            $body['v'] = true;
            $requests[$id] = array('url' => 'http://' . $order['eq']->getConfiguration('ip') . '/json/state', 'body' => json_encode($body));
        }
        $timeout = self::timeout() * 1000;
        $answers = static::multiPost($requests, min(3000, $timeout), $timeout);
        $errors = array();
        foreach ($_orders as $id => $order) {
            $eq = $order['eq'];
            $fragment = $order['fragment'];
            $state = isset($answers[$id]) ? json_decode($answers[$id], true) : null;
            if (is_array($state) && isset($state['state']) && is_array($state['state'])) {
                $state = $state['state'];
            }
            $verify = (int) $eq->getConfiguration('verify', 1) === 1;
            if (is_array($state) && isset($state['on']) && (!$verify || empty(self::mismatches($fragment, $state)))) {
                $eq->clearFailure();
                $eq->publishValues(self::stateValues($state, array(), $eq->getCache('fx_names', array()), $eq->getCache('pal_names', array())));
                $eq->verifyDone(true, $verify ? __('OK', __FILE__) : __('non vérifié', __FILE__));
                continue;
            }
            /* Premier envoi perdu ou écart : le chemin complet, avec relecture
             * et relances, pour ce membre seulement. */
            try {
                $eq->sendState($fragment);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        return $errors;
    }

    protected static function multiPost($_requests, $_connectMs, $_totalMs) {
        if (empty($_requests)) {
            return array();
        }
        $multi = curl_multi_init();
        $handles = array();
        foreach ($_requests as $key => $request) {
            $ch = curl_init($request['url']);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_CONNECTTIMEOUT_MS => $_connectMs,
                CURLOPT_TIMEOUT_MS        => $_totalMs,
                CURLOPT_PROXY             => '',
                CURLOPT_POST              => true,
                CURLOPT_POSTFIELDS        => $request['body'],
                CURLOPT_HTTPHEADER        => array('Content-Type: application/json'),
            ));
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active && curl_multi_select($multi, 0.2) === -1) {
                usleep(10000);
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

    /* Les commandes d'un groupe : chaque membre reçoit la sienne. Tous les
     * membres sont servis même si l'un échoue ; l'échec est rendu à la fin,
     * membre par membre. */
    private function runGroupAction($_logicalId, $_options) {
        $members = $this->members();
        if (empty($members)) {
            throw new Exception(__('Ce groupe n\'a aucun membre actif.', __FILE__));
        }
        $errors = array();
        $each = function ($_callback) use ($members, &$errors) {
            foreach ($members as $member) {
                try {
                    $_callback($member);
                } catch (Throwable $e) {
                    $errors[] = $member->getHumanName() . ' : ' . $e->getMessage();
                }
            }
        };

        if (strpos($_logicalId, 'scene::') === 0) {
            $ref = substr($_logicalId, 7);
            $each(function ($m) use ($ref) { $m->startScene($ref); });
        } else {
            switch ($_logicalId) {
                case 'refresh':
                    $each(function ($m) { $m->pollNow(); });
                    break;
                case 'scene_start':
                    $ref = isset($_options['title']) ? trim((string) $_options['title']) : '';
                    if ($ref === '') {
                        throw new Exception(__('Indiquez le nom de la scène dans le titre.', __FILE__));
                    }
                    $options = self::parseSceneOptions(isset($_options['message']) ? $_options['message'] : '');
                    $scene = self::findScene($ref);
                    $each(function ($m) use ($scene, $options) { $m->playScene($scene, $options); });
                    break;
                case 'scene_stop':
                    $each(function ($m) { $m->stopScenes(false); });
                    break;
                case 'scene_stop_all':
                    $each(function ($m) { $m->stopScenes(true); });
                    break;
                case 'text_show':
                    $text = isset($_options['message']) ? $_options['message'] : '';
                    $opts = isset($_options['title']) ? $_options['title'] : '';
                    $matrices = array_filter($members, function ($m) { return $m->isMatrix(); });
                    if (empty($matrices)) {
                        throw new Exception(__('Ce groupe ne contient aucune matrice.', __FILE__));
                    }
                    foreach ($matrices as $m) {
                        try {
                            $m->showText($text, $opts);
                        } catch (Throwable $e) {
                            $errors[] = $m->getHumanName() . ' : ' . $e->getMessage();
                        }
                    }
                    break;
                default:
                    /* Basculer : tout s'éteint si un seul membre est allumé,
                     * sinon tout s'allume — les lampes finissent d'accord. */
                    if ($_logicalId === 'toggle') {
                        $anyOn = false;
                        foreach ($members as $m) {
                            $anyOn = $anyOn || $m->isOn(false);
                        }
                        $_logicalId = $anyOn ? 'off_set' : 'on_set';
                    }
                    $orders = array();
                    foreach ($members as $m) {
                        try {
                            $fragment = $m->fragmentFor($_logicalId, $_options);
                        } catch (Throwable $e) {
                            $errors[] = $m->getHumanName() . ' : ' . $e->getMessage();
                            continue;
                        }
                        if ($fragment !== null) {
                            $m->abandonScenes();
                            $orders[$m->getId()] = array('eq' => $m, 'fragment' => $fragment);
                        }
                    }
                    $errors = array_merge($errors, static::sendStateMany($orders));
            }
        }
        $this->refreshGroup();
        $this->publishCmd('verify_ok', empty($errors) ? 1 : 0);
        $this->publishCmd('verify_detail', date('H:i:s') . ' ' . (empty($errors) ? __('OK', __FILE__) : implode(' ; ', $errors)));
        if (!empty($errors)) {
            throw new Exception($this->getHumanName() . ' : ' . implode(' ; ', $errors));
        }
        return true;
    }

    /* Infos du groupe, tirées de celles de ses membres. */
    public function refreshGroup() {
        $members = $this->members();
        $on = 0;
        $online = 0;
        foreach ($members as $m) {
            $on = $on || $m->isOn(false) ? 1 : 0;
            $cmd = $m->getCmd('info', 'online');
            $online += (is_object($cmd) && (int) $cmd->execCmd() === 1) ? 1 : 0;
        }
        $this->publishCmd('on', $on);
        $this->publishCmd('members_online', $online . '/' . count($members));
    }

    /* Listes du groupe : les effets et palettes que tous ses membres
     * connaissent, désignés par leur nom — leurs numéros diffèrent d'un
     * appareil à l'autre. */
    public function refreshGroupLists() {
        $effects = null;
        $palettes = null;
        foreach ($this->members() as $m) {
            $fx = array_values(array_filter((array) $m->getCache('fx_names', array()), function ($n) { return $n !== '' && $n !== 'RSVD' && $n !== '-'; }));
            $pal = array_values((array) $m->getCache('pal_names', array()));
            $effects = $effects === null ? $fx : array_values(array_intersect($effects, $fx));
            $palettes = $palettes === null ? $pal : array_values(array_intersect($palettes, $pal));
        }
        $effects = array_unique((array) $effects);
        natcasesort($effects);
        $list = array();
        foreach ($effects as $name) {
            $list[$name] = $name;
        }
        $this->updateList('effect_set', $list);
        $list = array();
        foreach (array_unique((array) $palettes) as $name) {
            $list[$name] = $name;
        }
        $this->updateList('palette_set', $list);
    }

    /* Les groupes dont cet appareil fait partie. */
    public function groups() {
        $groups = array();
        foreach (self::byType(__CLASS__) as $eqLogic) {
            if ($eqLogic->isGroup() && in_array((int) $this->getId(), self::parseMembers($eqLogic->getConfiguration('members', array())), true)) {
                $groups[] = $eqLogic;
            }
        }
        return $groups;
    }

    /* ============================================================ ACTIONS */

    /* Commandes qui ne sont pas un geste manuel sur la lumière : elles
     * laissent les scènes en place. */
    const SCENE_SAFE_ACTIONS = array('refresh', 'scene_start', 'scene_stop', 'scene_stop_all', 'text_show');

    public function runAction($_logicalId, $_options) {
        if ($this->isGroup()) {
            return $this->runGroupAction($_logicalId, $_options);
        }
        if (strpos($_logicalId, 'scene::') === 0) {
            return $this->startScene(substr($_logicalId, 7));
        }
        if (!in_array($_logicalId, self::SCENE_SAFE_ACTIONS, true)) {
            $this->abandonScenes();
        }
        switch ($_logicalId) {
            case 'refresh':
                return $this->pollNow();
            case 'scene_start':
                $ref = isset($_options['title']) ? trim((string) $_options['title']) : '';
                if ($ref === '') {
                    throw new Exception(__('Indiquez le nom de la scène dans le titre.', __FILE__));
                }
                return $this->startScene($ref, self::parseSceneOptions(isset($_options['message']) ? $_options['message'] : ''));
            case 'scene_stop':
                return $this->stopScenes(false);
            case 'scene_stop_all':
                return $this->stopScenes(true);
            case 'text_show':
                return $this->showText(isset($_options['message']) ? $_options['message'] : '', isset($_options['title']) ? $_options['title'] : '');
            case 'toggle':
                /* « on »:"t" ne se vérifie pas : on lit l'état et on envoie
                 * l'inverse, explicitement. */
                return $this->sendState(array('on' => !$this->isOn(true)));
        }
        $fragment = $this->fragmentFor($_logicalId, $_options);
        return $fragment === null ? true : $this->sendState($fragment);
    }

    /* L'appareil est-il allumé ? $_fresh : relu sur l'appareil, sinon la
     * dernière valeur connue. */
    public function isOn($_fresh) {
        if ($_fresh) {
            try {
                $state = $this->call('GET', '/json/state');
                return !empty($state['on']);
            } catch (Throwable $e) {
            }
        }
        $cmd = $this->getCmd('info', 'on');
        return is_object($cmd) && (int) $cmd->execCmd() === 1;
    }

    /* L'ordre WLED d'une commande simple, pour cet appareil. Commun à
     * l'équipement et aux groupes : un effet ou une palette désignés par leur
     * nom sont résolus appareil par appareil. null : rien à envoyer. */
    public function fragmentFor($_logicalId, $_options) {
        switch ($_logicalId) {
            case 'on_set':
                return array('on' => true);
            case 'off_set':
                return array('on' => false);
            case 'brightness_set':
                $pct = max(0, min(100, (int) (isset($_options['slider']) ? $_options['slider'] : 100)));
                if ($pct === 0) {
                    return array('on' => false);
                }
                return array('on' => true, 'bri' => max(1, (int) round($pct * 2.55)));
            case 'color_set':
                $color = self::hexToColor(isset($_options['color']) ? $_options['color'] : '');
                return array('on' => true, 'seg' => array('col' => array($color)));
            case 'effect_set':
                return array('on' => true, 'seg' => array('fx' => $this->resolveEffect($_options)));
            case 'palette_set':
                $pal = isset($_options['select']) ? trim((string) $_options['select']) : '';
                $id = is_numeric($pal) ? (int) $pal : $this->paletteIdByName($pal);
                if ($id === null || $pal === '') {
                    throw new Exception(sprintf(__('Palette inconnue sur ce WLED : %s', __FILE__), $pal));
                }
                return array('seg' => array('pal' => $id));
            case 'speed_set':
            case 'intensity_set':
                $value = max(0, min(255, (int) (isset($_options['slider']) ? $_options['slider'] : 128)));
                return array('seg' => array($_logicalId === 'speed_set' ? 'sx' : 'ix' => $value));
            case 'preset_set':
                $ps = isset($_options['select']) ? $_options['select'] : '';
                if (!is_numeric($ps) || (int) $ps <= 0) {
                    throw new Exception(__('Preset illisible :', __FILE__) . ' ' . $ps);
                }
                return array('ps' => (int) $ps);
            case 'json_set':
                $fragment = json_decode(isset($_options['message']) ? (string) $_options['message'] : '', true);
                if (!is_array($fragment) || empty($fragment)) {
                    throw new Exception(__('JSON illisible : attendu un objet comme {"on":true,"seg":{"fx":1}}.', __FILE__));
                }
                return $fragment;
        }
        return null;
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
        if ($this->isGroup()) {
            $this->createGroupCommands();
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
        $this->addCmdIfMissing('json_set', 'Envoyer un état JSON', 'action', 'message', array('order' => 110,
            'display' => array('title_disable' => 1, 'message_placeholder' => '{"on":true,"seg":{"fx":1}}')));
        $this->addCmdIfMissing('refresh', 'Rafraîchir', 'action', 'other', array('order' => 120, 'isVisible' => 1));

        $this->addCmdIfMissing('scene', 'Scène en cours', 'info', 'string', array('order' => 20, 'isVisible' => 1));
        $this->addCmdIfMissing('scene_active', 'Scène active', 'info', 'binary', array('order' => 21));
        $this->addCmdIfMissing('scene_until', 'Fin de la scène', 'info', 'string', array('order' => 22));
        $this->addCmdIfMissing('scene_start', 'Lancer une scène', 'action', 'message', array('order' => 200,
            'display' => array('title_placeholder' => __('Nom de la scène', __FILE__), 'message_placeholder' => 'durée=30 délai=10 priorité=90')));
        $this->addCmdIfMissing('scene_stop', 'Arrêter la scène en cours', 'action', 'other', array('order' => 201));
        $this->addCmdIfMissing('scene_stop_all', 'Arrêter toutes les scènes', 'action', 'other', array('order' => 202, 'isVisible' => 1));
        if ($this->isMatrix()) {
            $this->addTextCommand();
        }
        $this->syncSceneCommands(self::scenes());
    }

    private function addTextCommand() {
        $this->addCmdIfMissing('text_show', 'Afficher un texte', 'action', 'message', array('order' => 210,
            'display' => array('title_placeholder' => 'couleur=rouge durée=30 vitesse=200',
                               'message_placeholder' => __('Texte à faire défiler (32 caractères au plus)', __FILE__))));
    }

    /* Un groupe n'a pas d'état propre à relire : seulement ce qui se déduit
     * de ses membres, et les commandes qu'il leur transmet. */
    private function createGroupCommands() {
        $on = $this->addCmdIfMissing('on', 'Etat', 'info', 'binary', array('order' => 1, 'generic' => 'LIGHT_STATE_BOOL'));
        $this->addCmdIfMissing('members_online', 'Membres en ligne', 'info', 'string', array('order' => 2, 'isVisible' => 1));
        $this->addCmdIfMissing('verify_ok', 'Vérification', 'info', 'binary', array('order' => 3));
        $this->addCmdIfMissing('verify_detail', 'Dernière vérification', 'info', 'string', array('order' => 4));
        $this->addCmdIfMissing('on_set', 'Allumer', 'action', 'other', array('order' => 100, 'isVisible' => 1, 'generic' => 'LIGHT_ON', 'value' => $on));
        $this->addCmdIfMissing('off_set', 'Éteindre', 'action', 'other', array('order' => 101, 'isVisible' => 1, 'generic' => 'LIGHT_OFF', 'value' => $on));
        $this->addCmdIfMissing('toggle', 'Basculer', 'action', 'other', array('order' => 102, 'generic' => 'LIGHT_TOGGLE', 'value' => $on));
        $this->addCmdIfMissing('brightness_set', 'Régler la luminosité', 'action', 'slider', array(
            'order' => 103, 'isVisible' => 1, 'generic' => 'LIGHT_SLIDER', 'min' => 0, 'max' => 100));
        $this->addCmdIfMissing('color_set', 'Régler la couleur', 'action', 'color', array('order' => 104, 'isVisible' => 1, 'generic' => 'LIGHT_SET_COLOR'));
        $this->addCmdIfMissing('effect_set', 'Choisir un effet', 'action', 'select', array('order' => 105, 'isVisible' => 1, 'generic' => 'LIGHT_MODE'));
        $this->addCmdIfMissing('palette_set', 'Choisir une palette', 'action', 'select', array('order' => 106));
        $this->addCmdIfMissing('speed_set', 'Régler la vitesse', 'action', 'slider', array('order' => 107, 'min' => 0, 'max' => 255));
        $this->addCmdIfMissing('intensity_set', 'Régler l\'intensité', 'action', 'slider', array('order' => 108, 'min' => 0, 'max' => 255));
        $this->addCmdIfMissing('json_set', 'Envoyer un état JSON', 'action', 'message', array('order' => 110,
            'display' => array('title_disable' => 1, 'message_placeholder' => '{"on":true,"seg":{"fx":1}}')));
        $this->addCmdIfMissing('refresh', 'Rafraîchir', 'action', 'other', array('order' => 120, 'isVisible' => 1));
        $this->addCmdIfMissing('scene_start', 'Lancer une scène', 'action', 'message', array('order' => 200,
            'display' => array('title_placeholder' => __('Nom de la scène', __FILE__), 'message_placeholder' => 'durée=30 délai=10 priorité=90')));
        $this->addCmdIfMissing('scene_stop', 'Arrêter la scène en cours', 'action', 'other', array('order' => 201));
        $this->addCmdIfMissing('scene_stop_all', 'Arrêter toutes les scènes', 'action', 'other', array('order' => 202, 'isVisible' => 1));
        $this->addTextCommand();
        $this->syncSceneCommands(self::scenes());
    }

    /*
     * Une commande « Scène … » par scène de la bibliothèque, qui la lance avec
     * ses réglages par défaut. Une scène renommée garde sa commande (même
     * identifiant) et la renomme ; une scène supprimée perd la sienne.
     */
    public function syncSceneCommands($_scenes) {
        if ($this->getId() == '') {
            return;
        }
        $wanted = array();
        foreach ($_scenes as $rank => $scene) {
            $logicalId = 'scene::' . $scene['id'];
            $wanted[$logicalId] = 1;
            $name = cleanComponanteName(__('Scène', __FILE__) . ' ' . $scene['name']);
            $cmd = $this->getCmd('action', $logicalId);
            if (!is_object($cmd)) {
                $cmd = $this->addCmdIfMissing($logicalId, $name, 'action', 'other', array('order' => 300 + $rank));
                $cmd->setConfiguration('sceneName', $scene['name']);
                $cmd->save();
                continue;
            }
            /* Renommée seulement si la scène l'a été, et si l'utilisateur
             * n'a pas donné à la commande un nom à lui. */
            $previous = (string) $cmd->getConfiguration('sceneName', '');
            if ($previous === $scene['name']) {
                continue;
            }
            $previousName = cleanComponanteName(__('Scène', __FILE__) . ' ' . $previous);
            $other = cmd::byEqLogicIdCmdName($this->getId(), $name);
            if (($previous === '' || $cmd->getName() === $previousName)
                && (!is_object($other) || $other->getId() == $cmd->getId())) {
                $cmd->setName($name);
            }
            $cmd->setConfiguration('sceneName', $scene['name']);
            $cmd->save();
        }
        foreach ($this->getCmd('action') as $cmd) {
            if (strpos($cmd->getLogicalId(), 'scene::') === 0 && !isset($wanted[$cmd->getLogicalId()])) {
                $cmd->remove();
            }
        }
    }

    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd($_type, $_logicalId);
        if (is_object($cmd)) {
            /* Les indications d'affichage arrivées avec une version plus
             * récente sont ajoutées, sans toucher à celles déjà réglées. */
            $changed = false;
            foreach (isset($_options['display']) ? $_options['display'] : array() as $key => $value) {
                if ($cmd->getDisplay($key, '') === '') {
                    $cmd->setDisplay($key, $value);
                    $changed = true;
                }
            }
            if ($changed) {
                $cmd->save();
            }
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
        foreach (isset($_options['display']) ? $_options['display'] : array() as $key => $value) {
            $cmd->setDisplay($key, $value);
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
        /* Un membre qui s'allume ou s'éteint change l'état de ses groupes. */
        if (isset($_values['on']) && !$this->isGroup()) {
            $seen = $_values['on'] ? 'on' : 'off';
            if ($this->getCache('group_on_seen', '') !== $seen) {
                $this->setCache('group_on_seen', $seen);
                foreach ($this->groups() as $group) {
                    $group->refreshGroup();
                }
            }
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

    public function noteFailure($_message) {
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

    /* Le démon dit à chaque relecture du planning quels appareils lui sont
     * connectés en direct ; la valeur vieillit avec lui. */
    public function isLive() {
        $live = cache::byKey('wledbe::live')->getValue(array());
        return is_array($live) && isset($live['at'], $live['eqs']) && time() - (int) $live['at'] < 150
            && in_array((int) $this->getId(), (array) $live['eqs'], true);
    }

    public function toAjax() {
        if ($this->isGroup()) {
            $members = array();
            foreach ($this->members() as $m) {
                $cmd = $m->getCmd('info', 'online');
                $members[] = array('name' => $m->getHumanName(), 'online' => is_object($cmd) && (int) $cmd->execCmd() === 1,
                                   'on' => $m->isOn(false), 'matrix' => $m->isMatrix());
            }
            return array('id' => $this->getId(), 'group' => true, 'members' => $members);
        }
        return array(
            'id'        => $this->getId(),
            'group'     => false,
            'live'      => $this->isLive(),
            'online'    => (int) $this->getCache('failures', 0) === 0 && $this->getCache('raw_at', '') !== '',
            'failures'  => (int) $this->getCache('failures', 0),
            'problem'   => (string) $this->getCache('problem', ''),
            'fetchedAt' => (string) $this->getCache('raw_at', ''),
            'effects'   => count((array) $this->getCache('fx_names', array())),
            'palettes'  => count((array) $this->getCache('pal_names', array())),
            'presets'   => count((array) $this->getCache('preset_names', array())),
            'raw'       => $this->getCache('raw', array()),
            'stack'     => $this->stack(),
            'applied'   => (string) $this->sceneGet('applied_key', ''),
            'pending'   => is_array($this->sceneGet('pending_restore', null)),
        );
    }
}

/* Obligatoire même réduite : sans elle, le coeur refuse de créer ou d'ouvrir
 * un équipement du plugin. */
class wledbeCmd extends cmd {

    /* Les commandes « Scène … » appartiennent à la bibliothèque : seule
     * syncSceneCommands() les crée et les retire. Une page d'équipement
     * ouverte avant un enregistrement des scènes ne doit pas les effacer en
     * sauvegardant. */
    public function dontRemoveCmd() {
        return strpos((string) $this->getLogicalId(), 'scene::') === 0;
    }

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

/* Une scène injouable sur cet appareil (effet ou palette absents, scène
 * supprimée) : à distinguer d'un appareil muet, qu'on réessaie. */
class wledbeSceneError extends Exception {
}
