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

/*
 * Démon des scènes WLED : une minuterie, rien de plus.
 *
 * Il n'existe que parce que le cron de Jeedom ne descend pas sous la minute,
 * alors qu'une sonnette doit s'éteindre au bout de dix secondes. Il demande
 * au plugin le planning des réveils (un instant par appareil), attend, et
 * rappelle le plugin à l'heure dite. Il ne parle jamais à un WLED et ne
 * connaît ni les scènes ni les priorités : tout cela reste dans la classe,
 * à un seul endroit, et le cron passe par exactement le même chemin quand le
 * démon est arrêté.
 *
 * Il ne charge pas core.inc.php : PHP en ligne de commande et l'extension
 * curl, déjà exigée par Jeedom, suffisent.
 *
 *   php wledbed.php --callback URL --pid FICHIER --stamp FICHIER --keyfile FICHIER --loglevel debug --timezone Europe/Brussels
 *
 * La clé API arrive dans un fichier que le démon efface aussitôt lu, jamais
 * en argument : ps est lisible par tous les utilisateurs de la machine.
 */

error_reporting(E_ALL);
set_time_limit(0);
$options = getopt('', array('callback:', 'pid:', 'stamp:', 'keyfile:', 'loglevel:', 'timezone:'));
/* Le PHP en ligne de commande est souvent en UTC quand Jeedom est à l'heure
 * locale : sans le fuseau du plugin, le journal du démon serait décalé. */
if (!empty($options['timezone']) && in_array($options['timezone'], timezone_identifiers_list(), true)) {
    date_default_timezone_set($options['timezone']);
}
$callback = isset($options['callback']) ? $options['callback'] : '';
$pidFile  = isset($options['pid']) ? $options['pid'] : '';
$stamp    = isset($options['stamp']) ? $options['stamp'] : '';
$logLevel = isset($options['loglevel']) ? $options['loglevel'] : 'error';
$apiKey   = '';
if (!empty($options['keyfile']) && is_readable($options['keyfile'])) {
    $apiKey = trim((string) file_get_contents($options['keyfile']));
    @unlink($options['keyfile']);
}

/* Tous les niveaux que log::convertLogLevel() du coeur peut rendre. */
$levels = array('debug' => 0, 'info' => 1, 'notice' => 1, 'warning' => 2, 'error' => 3,
                'critical' => 3, 'alert' => 3, 'emergency' => 3, 'none' => 4);
$threshold = isset($levels[$logLevel]) ? $levels[$logLevel] : 3;

function wlLog($_level, $_message) {
    global $levels, $threshold;
    if ($levels[$_level] < $threshold) {
        return;
    }
    echo '[' . date('Y-m-d H:i:s') . '][' . strtoupper($_level) . '] : ' . $_message . "\n";
}

if ($callback === '' || $pidFile === '' || $apiKey === '') {
    wlLog('error', 'Arguments manquants : --callback, --pid et --keyfile (contenant la clé API) sont obligatoires.');
    exit(1);
}
if (!function_exists('curl_init')) {
    wlLog('error', 'L\'extension PHP curl est absente.');
    exit(1);
}

file_put_contents($pidFile, (string) getmypid());

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) { $running = false; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

/* Horloge monotone, pour les intervalles : un recalage NTP en arrière ne
 * doit pas figer le démon. L'heure murale ne sert qu'à comparer aux
 * échéances du planning. */
function wlClock() {
    return hrtime(true) / 1e9;
}

function wlHandle($_query) {
    global $callback, $apiKey;
    $url = $callback . (strpos($callback, '?') === false ? '?' : '&')
         . 'apikey=' . rawurlencode($apiKey) . '&' . $_query;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        /* Un réveil peut durer : il envoie des ordres vérifiés, avec leurs
         * relances, à un WLED parfois lent. */
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_PROXY          => '',
        /* Le callback est en boucle locale, parfois derrière un certificat
         * auto-signé si l'accès interne est en https. */
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ));
    return $ch;
}

/* Jeedom refuse la clé (clé régénérée, accès API restreint) : inutile
 * d'insister, le démon s'arrête. La gestion automatique du coeur le relance
 * avec la clé du moment. */
function wlRefused($_code) {
    if ($_code === 401 || $_code === 403) {
        wlLog('error', 'Jeedom refuse l\'accès (HTTP ' . $_code . ') : clé API changée ou accès API du plugin restreint. Arrêt du démon.');
        global $pidFile;
        @unlink($pidFile);
        exit(1);
    }
}

function wlSchedule() {
    $ch = wlHandle('action=schedule');
    $answer = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    wlRefused($code);
    if ($answer === false || $code !== 200) {
        return array(null, 'HTTP ' . $code . ' ' . $error);
    }
    $schedule = json_decode($answer, true);
    if (!is_array($schedule) || !isset($schedule['timers']) || !is_array($schedule['timers'])) {
        return array(null, 'planning illisible');
    }
    return array($schedule['timers'], '');
}

/* Réveille plusieurs appareils en parallèle : un WLED muet, dont le réveil
 * dure le temps de ses relances, ne retarde pas les autres. */
function wlTick($_eqs) {
    $multi = curl_multi_init();
    $handles = array();
    foreach ($_eqs as $eq => $name) {
        wlLog('debug', 'Réveil de ' . $name);
        $handles[$eq] = wlHandle('action=tick&eq=' . (int) $eq);
        curl_multi_add_handle($multi, $handles[$eq]);
    }
    do {
        $status = curl_multi_exec($multi, $active);
        if ($active && curl_multi_select($multi, 0.5) === -1) {
            usleep(20000);
        }
    } while ($active && $status == CURLM_OK);
    foreach ($handles as $eq => $ch) {
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code !== 200) {
            wlLog('warning', 'Réveil de ' . $_eqs[$eq] . ' en échec (HTTP ' . $code . ') ' . curl_error($ch));
        }
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        wlRefused($code);
    }
    curl_multi_close($multi);
}

/* ----------------------------------------------------------------- BOUCLE */

$timers = null;
$stampSeen = null;
$fetchedAt = -INF;
$failures = 0;
$retryAt = -INF;
$lastTick = array();   /* [eq] => instant (horloge monotone) du dernier rappel */

wlLog('info', 'Démarrage du démon WLED (PID ' . getmypid() . ')');

while ($running) {
    $clock = wlClock();

    /* Le planning est relu quand le plugin le signale — il récrit un fichier
     * témoin à chaque changement de scène — et de toute façon chaque minute,
     * pour ne jamais rester désynchronisé. En cas d'échec, les essais
     * s'espacent (5 s, 10 s… jusqu'à la minute) au lieu de marteler Jeedom. */
    $content = ($stamp !== '' && is_readable($stamp)) ? @file_get_contents($stamp) : '';
    if (($timers === null || $content !== $stampSeen || $clock - $fetchedAt > 60) && $clock >= $retryAt) {
        $stampNow = $content;
        list($fresh, $problem) = wlSchedule();
        if ($fresh !== null) {
            if ($failures > 0) {
                wlLog('info', 'Jeedom de nouveau joignable');
            }
            if ($timers === null || count($fresh) !== count($timers)) {
                wlLog('debug', count($fresh) . ' réveil(s) au planning');
            }
            $timers = $fresh;
            $stampSeen = $stampNow;
            $fetchedAt = $clock;
            $failures = 0;
        } else {
            $failures++;
            $retryAt = $clock + min(60, 5 * (1 << min(4, $failures - 1)));
            if ($failures === 1) {
                wlLog('warning', 'Callback Jeedom en échec (' . $problem . '), nouvel essai en s\'espaçant');
            }
        }
    }

    $due = array();
    foreach ((array) $timers as $timer) {
        $eq = (int) $timer['eq'];
        if ((float) $timer['at'] > microtime(true)) {
            continue;
        }
        /* Garde-fou : un appareil dont le réveil reste dans le passé n'est
         * pas rappelé plus d'une fois par seconde. */
        if (isset($lastTick[$eq]) && $clock - $lastTick[$eq] < 1) {
            continue;
        }
        $lastTick[$eq] = $clock;
        $due[$eq] = isset($timer['name']) ? $timer['name'] : '#' . $eq;
    }
    if (!empty($due)) {
        wlTick($due);
        /* Un rappel change le planning : on le relit au tour suivant. */
        $timers = null;
    }

    usleep(200000);
}

@unlink($pidFile);
wlLog('info', 'Arrêt du démon WLED');
