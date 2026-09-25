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
 *   php wledbed.php --callback URL --pid FICHIER --stamp FICHIER --loglevel debug --timezone Europe/Brussels
 *
 * La clé API arrive par l'entrée standard, jamais en argument : ps est
 * lisible par tous les utilisateurs de la machine.
 */

error_reporting(E_ALL);
set_time_limit(0);
$options = getopt('', array('callback:', 'pid:', 'stamp:', 'loglevel:', 'timezone:'));
/* Le PHP en ligne de commande est souvent en UTC quand Jeedom est à l'heure
 * locale : sans le fuseau du plugin, le journal du démon serait décalé. */
if (!empty($options['timezone']) && in_array($options['timezone'], timezone_identifiers_list(), true)) {
    date_default_timezone_set($options['timezone']);
}
$callback = isset($options['callback']) ? $options['callback'] : '';
$pidFile  = isset($options['pid']) ? $options['pid'] : '';
$stamp    = isset($options['stamp']) ? $options['stamp'] : '';
$logLevel = isset($options['loglevel']) ? $options['loglevel'] : 'error';
$apiKey   = trim((string) fgets(STDIN));

$levels = array('debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'none' => 4);
$threshold = isset($levels[$logLevel]) ? $levels[$logLevel] : 3;

function wlLog($_level, $_message) {
    global $levels, $threshold;
    if ($levels[$_level] < $threshold) {
        return;
    }
    echo '[' . date('Y-m-d H:i:s') . '][' . strtoupper($_level) . '] : ' . $_message . "\n";
}

if ($callback === '' || $pidFile === '' || $apiKey === '') {
    wlLog('error', 'Arguments manquants : --callback, --pid et la clé API sur l\'entrée standard sont obligatoires.');
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

/* Appel au plugin. Un tick peut durer : il envoie des ordres vérifiés, avec
 * leurs relances, à un WLED parfois lent. */
function wlCallback($_query) {
    global $callback, $apiKey;
    $url = $callback . (strpos($callback, '?') === false ? '?' : '&')
         . 'apikey=' . rawurlencode($apiKey) . '&' . $_query;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_PROXY          => '',
        /* Le callback est en boucle locale, parfois derrière un certificat
         * auto-signé si l'accès interne est en https. */
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ));
    $answer = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($answer === false || $code !== 200) {
        wlLog('warning', 'Callback Jeedom en échec (' . $code . ') ' . $error);
        return null;
    }
    return $answer;
}

function wlSchedule() {
    $answer = wlCallback('action=schedule');
    if ($answer === null) {
        return null;
    }
    $schedule = json_decode($answer, true);
    if (!is_array($schedule) || !isset($schedule['timers']) || !is_array($schedule['timers'])) {
        wlLog('error', 'Planning illisible reçu de Jeedom.');
        return null;
    }
    return $schedule['timers'];
}

/* ----------------------------------------------------------------- BOUCLE */

$timers = null;
$stampSeen = -1;
$fetchedAt = 0;
$lastTick = array();   /* [eq] => instant du dernier rappel */

wlLog('info', 'Démarrage du démon WLED (PID ' . getmypid() . ')');

while ($running) {
    $now = microtime(true);

    /* Le planning est relu quand le plugin le signale — il touche un fichier
     * témoin à chaque changement de scène — et de toute façon chaque minute,
     * pour ne jamais rester désynchronisé. */
    clearstatcache();
    $mtime = ($stamp !== '' && file_exists($stamp)) ? filemtime($stamp) : 0;
    if ($timers === null || $mtime !== $stampSeen || $now - $fetchedAt > 60) {
        $fresh = wlSchedule();
        if ($fresh !== null) {
            if ($timers === null || count($fresh) !== count($timers)) {
                wlLog('debug', count($fresh) . ' réveil(s) au planning');
            }
            $timers = $fresh;
            $stampSeen = $mtime;
            $fetchedAt = $now;
        } elseif ($timers === null) {
            sleep(5);
            continue;
        }
    }

    $ticked = false;
    foreach ($timers as $timer) {
        $eq = (int) $timer['eq'];
        if ((float) $timer['at'] > $now) {
            continue;
        }
        /* Garde-fou : un appareil dont le réveil reste dans le passé (le
         * plugin n'a pas pu agir) n'est pas rappelé plus d'une fois toutes
         * les deux secondes. */
        if (isset($lastTick[$eq]) && $now - $lastTick[$eq] < 2) {
            continue;
        }
        $lastTick[$eq] = $now;
        wlLog('debug', 'Réveil de ' . (isset($timer['name']) ? $timer['name'] : '#' . $eq));
        wlCallback('action=tick&eq=' . $eq);
        $ticked = true;
    }
    /* Un rappel change le planning : on le relit au tour suivant. */
    if ($ticked) {
        $timers = null;
    }

    usleep(200000);
}

@unlink($pidFile);
wlLog('info', 'Arrêt du démon WLED');
