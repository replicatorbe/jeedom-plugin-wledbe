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

/*
 * Exécute des requêtes vers Jeedom en continuant de servir les WebSocket :
 * un réveil peut durer (ordres vérifiés vers un WLED lent), et pendant ce
 * temps les connexions doivent être lues — sans quoi les états poussés
 * s'accumulent et la connexion passe pour morte.
 */
function wlRun($_handles) {
    $multi = curl_multi_init();
    foreach ($_handles as $ch) {
        curl_multi_add_handle($multi, $ch);
    }
    do {
        $status = curl_multi_exec($multi, $active);
        if ($active) {
            wsPoll(0.05);
        }
    } while ($active && $status == CURLM_OK);
    $out = array();
    foreach ($_handles as $key => $ch) {
        $out[$key] = array('code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                           'body' => curl_multi_getcontent($ch), 'error' => curl_error($ch));
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $out;
}

function wlSchedule($_connected) {
    $r = wlRun(array(wlHandle('action=schedule&connected=' . implode(',', $_connected))))[0];
    wlRefused($r['code']);
    if ($r['code'] !== 200 || !is_string($r['body'])) {
        return array(null, 'HTTP ' . $r['code'] . ' ' . $r['error']);
    }
    $schedule = json_decode($r['body'], true);
    if (!is_array($schedule) || !isset($schedule['timers']) || !is_array($schedule['timers'])) {
        return array(null, 'planning illisible');
    }
    return array($schedule, '');
}

/* Réveille plusieurs appareils en parallèle : un WLED muet, dont le réveil
 * dure le temps de ses relances, ne retarde pas les autres. */
function wlTick($_eqs) {
    $handles = array();
    foreach ($_eqs as $eq => $name) {
        wlLog('debug', 'Réveil de ' . $name);
        $handles[$eq] = wlHandle('action=tick&eq=' . (int) $eq);
    }
    foreach (wlRun($handles) as $eq => $r) {
        if ($r['code'] !== 200) {
            wlLog('warning', 'Réveil de ' . $_eqs[$eq] . ' en échec (HTTP ' . $r['code'] . ') ' . $r['error']);
        }
        wlRefused($r['code']);
    }
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
 * Un client WebSocket minimal (RFC 6455) : poignée de main HTTP, trames texte
 * fragmentées réassemblées, ping/pong, fermeture. Les trames d'un client sont
 * masquées. Tout ce qui s'écrit passe par un tampon de sortie, vidé quand la
 * connexion est prête : une socket non bloquante peut n'accepter qu'une
 * partie d'une écriture.
 *
 * WLED ne parle que quand son état change, et un ESP8266 ferme le plus
 * ancien de ses clients WebSocket au-delà de trois : le démon envoie un ping
 * toutes les trente secondes pour s'assurer que la connexion vit.
 */

$live = array();      /* [eq] => connexion */
$pushes = array();    /* [eq] => dernier état reçu, pas encore transmis */
$pushedAt = -INF;
$pushRetryAt = -INF;
$pushFailures = 0;

/* Une connexion n'est jugée stable qu'après ce temps : un appareil qui
 * accepte puis ferme aussitôt (trop de clients) voit ses essais s'espacer. */
const WS_STABLE = 60;

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

function wsSend(&$_c, $_data) {
    $_c['out'] .= $_data;
    wsWrite($_c);
}

/* Écrit ce que la socket accepte ; le reste attend le tour suivant. Une
 * écriture refusée dès la poignée de main, c'est une connexion refusée : la
 * connexion non bloquante se dit prête même quand elle a échoué. */
function wsWrite(&$_c) {
    if ($_c['out'] === '' || !is_resource($_c['sock'])) {
        return;
    }
    $n = @fwrite($_c['sock'], $_c['out']);
    if ($n === false) {
        wsClose($_c, $_c['state'] === 'handshake' ? 'connexion refusée' : 'écriture impossible');
        return;
    }
    $_c['out'] = (string) substr($_c['out'], $n);
}

function wsClose(&$_c, $_why) {
    if (is_resource($_c['sock'])) {
        if ($_c['state'] === 'open') {
            @fwrite($_c['sock'], wsFrame(8, ''));
        }
        @fclose($_c['sock']);
    }
    $clock = wlClock();
    $stable = $_c['state'] === 'open' && $clock - $_c['openedAt'] >= WS_STABLE;
    /* Une connexion qui a tenu repart de zéro ; une qui tombe aussitôt
     * ouverte, ou qui ne s'ouvre pas, espace ses essais jusqu'à deux
     * minutes. */
    $_c['fails'] = $stable ? 1 : $_c['fails'] + 1;
    $_c['sock'] = null;
    $_c['state'] = 'idle';
    $_c['buf'] = '';
    $_c['frag'] = '';
    $_c['out'] = '';
    $_c['next'] = $clock + min(120, 5 * (1 << min(5, $_c['fails'] - 1)));
    /* Une ligne par panne, pas une par tentative. */
    if (!$_c['down']) {
        $_c['down'] = true;
        wlLog('info', 'Connexion directe à ' . $_c['name'] . ' perdue (' . $_why . '), état relu chaque minute en attendant');
    } else {
        wlLog('debug', 'Connexion directe à ' . $_c['name'] . ' : ' . $_why);
    }
}

function wsConnect(&$_c) {
    $sock = @stream_socket_client('tcp://' . $_c['ip'] . ':' . $_c['port'], $errno, $error, 0,
        STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
    if ($sock === false) {
        $_c['state'] = 'connecting';
        wsClose($_c, $error !== '' ? $error : 'connexion impossible');
        return;
    }
    stream_set_blocking($sock, false);
    $_c['sock'] = $sock;
    $_c['state'] = 'connecting';
    $_c['deadline'] = wlClock() + 5;
}

/* Poignée de main : la clé envoyée, et la réponse qu'elle doit produire. */
function wsHandshake(&$_c) {
    $key = base64_encode(random_bytes(16));
    $_c['accept'] = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $_c['state'] = 'handshake';
    $_c['last'] = wlClock();
    wsSend($_c, "GET /ws HTTP/1.1\r\nHost: " . $_c['ip'] . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: " . $key . "\r\nSec-WebSocket-Version: 13\r\n\r\n");
}

/* Lit ce qui est arrivé ; rend les messages texte complets. */
function wsRead(&$_c) {
    $chunk = @fread($_c['sock'], 65536);
    if ($chunk === false || ($chunk === '' && feof($_c['sock']))) {
        wsClose($_c, 'fermée par l\'appareil');
        return array();
    }
    if ($chunk === '') {
        return array();
    }
    $_c['buf'] .= $chunk;
    $_c['last'] = wlClock();
    $messages = array();

    if ($_c['state'] === 'handshake') {
        $end = strpos($_c['buf'], "\r\n\r\n");
        if ($end === false) {
            if (strlen($_c['buf']) > 8192) {
                wsClose($_c, 'réponse illisible');
            }
            return array();
        }
        $head = substr($_c['buf'], 0, $end);
        if (!preg_match('#^HTTP/1\.[01] 101 #', $head)
            || !preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $head, $m) || $m[1] !== $_c['accept']) {
            wsClose($_c, 'refusée : ' . strtok($head, "\r\n"));
            return array();
        }
        $_c['buf'] = (string) substr($_c['buf'], $end + 4);
        $_c['state'] = 'open';
        $_c['openedAt'] = wlClock();
        $_c['pingAt'] = wlClock();
        /* Le retour n'est annoncé qu'une fois la connexion stable (voir
         * wsPoll) : un appareil qui accepte puis ferme aussitôt ne doit pas
         * écrire deux lignes par essai. */
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
        if ($len < 0 || $len > 1048576) {
            wsClose($_c, 'trame démesurée');
            return array();
        }
        /* Un serveur ne masque pas ses trames ; si c'était le cas, la clé
         * suivrait la longueur. */
        $masked = ($b2 & 128) !== 0;
        if ($masked) {
            $off += 4;
        }
        if (strlen($_c['buf']) < $off + $len) {
            break;
        }
        $payload = (string) substr($_c['buf'], $off, $len);
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
            case 1:   /* début d'un message texte */
            case 2:   /* début d'un message binaire, ignoré */
                $_c['fragOp'] = $opcode;
                $_c['frag'] = $payload;
                break;
            case 0:   /* suite du message en cours */
                $_c['frag'] .= $payload;
                break;
            case 8:   /* fermeture */
                wsClose($_c, 'fermée par l\'appareil');
                return $messages;
            case 9:   /* ping : on répond pong, avec le même contenu */
                wsSend($_c, wsFrame(10, $payload));
                continue 2;
            default:  /* pong, ou opcode inconnu */
                continue 2;
        }
        if (strlen($_c['frag']) > 1048576) {
            wsClose($_c, 'message démesuré');
            return array();
        }
        if ($fin) {
            if ($_c['fragOp'] === 1) {
                $messages[] = $_c['frag'];
            }
            $_c['frag'] = '';
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
        $port = isset($device['port']) ? (int) $device['port'] : 80;
        $seen[$eq] = true;
        if (isset($live[$eq]) && ($live[$eq]['ip'] !== $device['ip'] || $live[$eq]['port'] !== $port)) {
            wsClose($live[$eq], 'adresse changée');
            unset($live[$eq]);
        }
        if (!isset($live[$eq])) {
            $live[$eq] = array('ip' => $device['ip'], 'port' => $port, 'name' => $device['name'], 'sock' => null,
                               'state' => 'idle', 'buf' => '', 'frag' => '', 'fragOp' => 1, 'out' => '', 'accept' => '',
                               'fails' => 0, 'down' => false, 'next' => wlClock(), 'last' => wlClock(),
                               'pingAt' => wlClock(), 'openedAt' => 0, 'deadline' => 0);
        }
    }
    foreach (array_keys($live) as $eq) {
        if (!isset($seen[$eq])) {
            if (is_resource($live[$eq]['sock'])) {
                @fclose($live[$eq]['sock']);
            }
            unset($live[$eq]);
        }
    }
}

/* Un tour de WebSocket : connexions, écritures en attente, lectures, pings.
 * Attend au plus $_timeout secondes qu'il se passe quelque chose : c'est
 * aussi le rythme de la boucle principale. */
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
            if ($c['state'] === 'open' && $clock - $c['pingAt'] > 30) {
                $c['pingAt'] = $clock;
                wsSend($c, wsFrame(9, 'jeedom'));
                if ($c['state'] !== 'open') {
                    continue;
                }
            }
            $read[$eq] = $c['sock'];
            if ($c['out'] !== '') {
                $write[$eq] = $c['sock'];
            }
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
    if ($n !== false && $n > 0) {
        foreach ($write as $eq => $sock) {
            if (!in_array($sock, $w, true) || !isset($live[$eq]) || $live[$eq]['sock'] !== $sock) {
                continue;
            }
            if ($live[$eq]['state'] === 'connecting') {
                wsHandshake($live[$eq]);
            } else {
                wsWrite($live[$eq]);
            }
        }
        foreach ($read as $eq => $sock) {
            if (!in_array($sock, $r, true) || !isset($live[$eq]) || $live[$eq]['sock'] !== $sock) {
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
    /* Le silence se juge après la lecture : ce qui attendait dans la socket
     * vient d'être lu. Avec un ping toutes les trente secondes, un appareil
     * vivant répond toujours avant. */
    $clock = wlClock();
    foreach ($live as $eq => &$c) {
        if (($c['state'] === 'handshake' || $c['state'] === 'open') && $clock - $c['last'] > 75) {
            wsClose($c, 'silence');
        } elseif ($c['state'] === 'open' && $c['down'] && $clock - $c['openedAt'] >= WS_STABLE) {
            $c['down'] = false;
            wlLog('info', 'Connexion directe à ' . $c['name'] . ' rétablie');
        }
    }
    unset($c);
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
 * seul le dernier compte. En cas d'échec, ils sont gardés — sauf si un plus
 * récent est arrivé entre-temps — et les essais s'espacent. */
function wsFlush() {
    global $pushes, $pushedAt, $pushRetryAt, $pushFailures;
    $clock = wlClock();
    if (empty($pushes) || $clock - $pushedAt < 0.5 || $clock < $pushRetryAt) {
        return;
    }
    $sending = $pushes;
    $pushes = array();
    $pushedAt = $clock;
    $parts = array();
    foreach ($sending as $eq => $message) {
        $parts[] = '"' . (int) $eq . '":' . $message;
    }
    $ch = wlHandle('action=push');
    curl_setopt_array($ch, array(
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => '{"pushes":{' . implode(',', $parts) . '}}',
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_TIMEOUT    => 30,
    ));
    $r = wlRun(array($ch))[0];
    wlRefused($r['code']);
    if ($r['code'] === 200) {
        $pushFailures = 0;
        return;
    }
    $pushes = $pushes + $sending;
    $pushFailures++;
    $pushRetryAt = wlClock() + min(30, 2 * (1 << min(4, $pushFailures - 1)));
    if ($pushFailures === 1) {
        wlLog('warning', 'Transmission des états à Jeedom en échec (HTTP ' . $r['code'] . '), nouvel essai en s\'espaçant');
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
