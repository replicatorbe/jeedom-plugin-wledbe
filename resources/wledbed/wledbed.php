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

function wlSchedule($_connected) {
    $ch = wlHandle('action=schedule&connected=' . implode(',', $_connected));
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
    return array($schedule, '');
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


/* ------------------------------------------------------------ WEBSOCKET */

/*
 * Une connexion WebSocket par WLED (ws://ip/ws). WLED y pousse son état
 * complet — le même JSON que /json/si — à chaque changement : bouton de
 * l'appareil, appli WLED, autre système. Le démon le transmet au plugin, qui
 * l'affiche aussitôt et, pour une scène sous garde, la réimpose sans attendre
 * son tour de garde. Le relevé de chaque minute reste en place : si la
 * connexion tombe, rien ne se perd.
 *
 * Un client WebSocket minimal : poignée de main HTTP, trames texte
 * fragmentées réassemblées, ping/pong, fermeture. Les trames envoyées par un
 * client doivent être masquées (RFC 6455).
 */

$live = array();      /* [eq] => connexion */
$pushes = array();    /* [eq] => dernier état reçu, pas encore transmis */
$pushedAt = -INF;

function wsFrame($_opcode, $_payload) {
    $len = strlen($_payload);
    $head = chr(0x80 | $_opcode);
    if ($len < 126) {
        $head .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $head .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $head .= chr(0x80 | 127) . pack('J', $len);
    }
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $len; $i++) {
        $masked .= $_payload[$i] ^ $mask[$i % 4];
    }
    return $head . $mask . $masked;
}

function wsClose(&$_c, $_why) {
    global $live;
    if (is_resource($_c['sock'])) {
        @fwrite($_c['sock'], wsFrame(8, ''));
        @fclose($_c['sock']);
    }
    $wasOpen = $_c['state'] === 'open';
    $_c['sock'] = null;
    $_c['state'] = 'idle';
    $_c['buf'] = '';
    $_c['frag'] = '';
    $_c['fails']++;
    $_c['next'] = wlClock() + min(120, 5 * (1 << min(5, $_c['fails'] - 1)));
    /* Une ligne par coupure, pas une par tentative : un WLED éteint pour la
     * nuit ne doit pas remplir le journal. */
    if ($wasOpen || $_c['fails'] === 1) {
        wlLog($wasOpen ? 'info' : 'debug', 'Connexion directe à ' . $_c['name'] . ' perdue (' . $_why . ')');
    }
}

function wsConnect(&$_c) {
    $sock = @stream_socket_client('tcp://' . $_c['ip'] . ':80', $errno, $error, 0,
        STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
    if ($sock === false) {
        wsClose($_c, $error !== '' ? $error : 'connexion impossible');
        return;
    }
    stream_set_blocking($sock, false);
    $_c['sock'] = $sock;
    $_c['state'] = 'connecting';
    $_c['deadline'] = wlClock() + 5;
}

/* Lit ce qui est arrivé ; rend les messages texte complets. */
function wsRead(&$_c) {
    $chunk = @fread($_c['sock'], 65536);
    if ($chunk === false || ($chunk === '' && feof($_c['sock']))) {
        wsClose($_c, 'fermée par l\'appareil');
        return array();
    }
    $_c['buf'] .= $chunk;
    $_c['last'] = wlClock();
    $messages = array();

    if ($_c['state'] === 'handshake') {
        $end = strpos($_c['buf'], "\r\n\r\n");
        if ($end === false) {
            return array();
        }
        $status = strtok($_c['buf'], "\r\n");
        if (strpos($status, ' 101') === false) {
            wsClose($_c, 'refusée : ' . $status);
            return array();
        }
        $_c['buf'] = substr($_c['buf'], $end + 4);
        $_c['state'] = 'open';
        if ($_c['fails'] > 0) {
            wlLog('info', 'Connexion directe à ' . $_c['name'] . ' établie');
        }
        $_c['fails'] = 0;
    }

    while (strlen($_c['buf']) >= 2) {
        $b1 = ord($_c['buf'][0]);
        $b2 = ord($_c['buf'][1]);
        $len = $b2 & 127;
        $off = 2;
        if ($len === 126) {
            if (strlen($_c['buf']) < 4) {
                break;
            }
            $len = unpack('n', substr($_c['buf'], 2, 2))[1];
            $off = 4;
        } elseif ($len === 127) {
            if (strlen($_c['buf']) < 10) {
                break;
            }
            $len = unpack('J', substr($_c['buf'], 2, 8))[1];
            $off = 10;
        }
        /* Un serveur ne masque pas ses trames ; si c'était le cas, la clé
         * suivrait la longueur. */
        $masked = ($b2 & 128) !== 0;
        if ($masked) {
            $off += 4;
        }
        if ($len > 1048576) {
            wsClose($_c, 'trame démesurée');
            return array();
        }
        if (strlen($_c['buf']) < $off + $len) {
            break;
        }
        $payload = substr($_c['buf'], $off, $len);
        if ($masked) {
            $mask = substr($_c['buf'], $off - 4, 4);
            for ($i = 0; $i < $len; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }
        $_c['buf'] = (string) substr($_c['buf'], $off + $len);
        $opcode = $b1 & 15;
        $fin = ($b1 & 128) !== 0;
        switch ($opcode) {
            case 0:   /* suite d'un message fragmenté */
            case 1:   /* texte */
                $_c['frag'] = ($opcode === 1 ? '' : $_c['frag']) . $payload;
                if (strlen($_c['frag']) > 1048576) {
                    wsClose($_c, 'message démesuré');
                    return array();
                }
                if ($fin) {
                    $messages[] = $_c['frag'];
                    $_c['frag'] = '';
                }
                break;
            case 8:   /* fermeture */
                wsClose($_c, 'fermée par l\'appareil');
                return $messages;
            case 9:   /* ping : on répond pong, avec le même contenu */
                @fwrite($_c['sock'], wsFrame(10, $payload));
                break;
        }
    }
    return $messages;
}

/* Tient la liste des connexions en accord avec celle du plugin. */
function wsSync($_wanted) {
    global $live;
    $seen = array();
    foreach ($_wanted as $device) {
        $eq = (int) $device['eq'];
        $seen[$eq] = true;
        if (isset($live[$eq]) && $live[$eq]['ip'] !== $device['ip']) {
            wsClose($live[$eq], 'adresse changée');
            unset($live[$eq]);
        }
        if (!isset($live[$eq])) {
            $live[$eq] = array('ip' => $device['ip'], 'name' => $device['name'], 'sock' => null, 'state' => 'idle',
                               'buf' => '', 'frag' => '', 'fails' => 0, 'next' => wlClock(), 'last' => wlClock(), 'pingAt' => wlClock());
        }
    }
    foreach (array_keys($live) as $eq) {
        if (!isset($seen[$eq])) {
            wsClose($live[$eq], 'retiré');
            unset($live[$eq]);
        }
    }
}

/* Un tour de WebSocket : connexions, lectures, pings. Attend au plus
 * $_timeout secondes qu'il se passe quelque chose : c'est aussi le rythme de
 * la boucle principale. */
function wsPoll($_timeout) {
    global $live, $pushes;
    $clock = wlClock();
    $read = array();
    $write = array();
    foreach ($live as $eq => &$c) {
        if ($c['state'] === 'idle' && $clock >= $c['next']) {
            wsConnect($c);
        }
        if ($c['state'] === 'connecting') {
            if ($clock > $c['deadline']) {
                wsClose($c, 'délai de connexion dépassé');
                continue;
            }
            $write[$eq] = $c['sock'];
        } elseif ($c['state'] === 'handshake' || $c['state'] === 'open') {
            /* Un WLED muet depuis trop longtemps (Wi-Fi perdu sans fermeture
             * propre) : on raccroche pour se reconnecter. */
            if ($clock - $c['last'] > 75) {
                wsClose($c, 'silence');
                continue;
            }
            if ($c['state'] === 'open' && $clock - $c['pingAt'] > 30) {
                $c['pingAt'] = $clock;
                @fwrite($c['sock'], wsFrame(9, 'jeedom'));
            }
            $read[$eq] = $c['sock'];
        }
    }
    unset($c);
    if (empty($read) && empty($write)) {
        usleep((int) ($_timeout * 1000000));
        return;
    }
    $r = array_values($read);
    $w = array_values($write);
    $e = null;
    $n = @stream_select($r, $w, $e, 0, (int) ($_timeout * 1000000));
    if ($n === false || $n === 0) {
        return;
    }
    foreach ($write as $eq => $sock) {
        if (!in_array($sock, $w, true)) {
            continue;
        }
        $c = &$live[$eq];
        $key = base64_encode(random_bytes(16));
        $request = "GET /ws HTTP/1.1\r\nHost: " . $c['ip'] . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                 . "Sec-WebSocket-Key: " . $key . "\r\nSec-WebSocket-Version: 13\r\n\r\n";
        if (@fwrite($sock, $request) === false) {
            wsClose($c, 'connexion refusée');
        } else {
            $c['state'] = 'handshake';
            $c['last'] = wlClock();
            $c['pingAt'] = wlClock();
        }
        unset($c);
    }
    foreach ($read as $eq => $sock) {
        if (!in_array($sock, $r, true)) {
            continue;
        }
        foreach (wsRead($live[$eq]) as $message) {
            /* Seul l'état intéresse le plugin ; le dernier reçu suffit. */
            $data = json_decode($message, true);
            if (is_array($data) && isset($data['state'])) {
                $pushes[$eq] = $message;
            }
        }
    }
}

function wsConnected() {
    global $live;
    $eqs = array();
    foreach ($live as $eq => $c) {
        if ($c['state'] === 'open') {
            $eqs[] = $eq;
        }
    }
    return $eqs;
}

/* Transmet au plugin les états reçus, au plus deux fois par seconde : un
 * curseur qu'on fait glisser dans l'appli WLED pousse des dizaines d'états,
 * seul le dernier compte. */
function wsFlush() {
    global $pushes, $pushedAt;
    if (empty($pushes) || wlClock() - $pushedAt < 0.5) {
        return;
    }
    $parts = array();
    foreach ($pushes as $eq => $message) {
        $parts[] = '"' . (int) $eq . '":' . $message;
    }
    $pushes = array();
    $pushedAt = wlClock();
    $ch = wlHandle('action=push');
    curl_setopt_array($ch, array(
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => '{"pushes":{' . implode(',', $parts) . '}}',
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_TIMEOUT    => 30,
    ));
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    wlRefused($code);
    if ($code !== 200) {
        wlLog('debug', 'Transmission des états en échec (HTTP ' . $code . ')');
    }
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
        list($schedule, $problem) = wlSchedule(wsConnected());
        $fresh = $schedule === null ? null : $schedule['timers'];
        if ($fresh !== null) {
            wsSync(isset($schedule['live']) && is_array($schedule['live']) ? $schedule['live'] : array());
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

    /* Les WebSocket rythment la boucle : on y attend au plus 200 ms. */
    wsPoll(0.2);
    wsFlush();
}

foreach ($live as $eq => $c) {
    if (is_resource($c['sock'])) {
        @fclose($c['sock']);
    }
}
@unlink($pidFile);
wlLog('info', 'Arrêt du démon WLED');
