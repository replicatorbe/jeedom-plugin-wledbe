<?php
/* Rejeu hors ligne du plugin sur des réponses de WLED.
 *
 *   php tests/run.php
 *
 * Les fixtures strip_* de tests/fixtures/ ont été relevées sur un vrai WLED
 * 16.0.0 (ESP8266, bande de 240 LED), identifiants réseau masqués. Chaque
 * valeur douteuse rencontrée en production doit y laisser un fichier : c'est
 * ce qui transforme un incident en test de non-régression. */

require_once __DIR__ . '/stub.php';

/* La classe charge le coeur de Jeedom en première ligne ; hors installation, on
 * la recopie sans ce require et on l'inclut. La copie est déposée à côté de
 * l'originale et effacée en sortant, même sur erreur fatale. */
$original = __DIR__ . '/../core/class/wledbe.class.php';
$copy = __DIR__ . '/../core/class/.wledbe.test.php';
$source = preg_replace('#^\s*require_once .*core\.inc\.php.*$#m', '', file_get_contents($original));
file_put_contents($copy, $source);
register_shutdown_function(function () use ($copy) {
    if (file_exists($copy)) { unlink($copy); }
});
require_once $copy;

$passed = 0;
$failed = 0;

function check($_label, $_actual, $_expected) {
    global $passed, $failed;
    if ($_actual === $_expected) {
        $passed++;
        printf("  ok    %-62s %s\n", $_label, str_replace("\n", ' ', var_export($_actual, true)));
    } else {
        $failed++;
        printf("  ECHEC %-62s obtenu %s, attendu %s\n", $_label,
            str_replace("\n", ' ', var_export($_actual, true)), str_replace("\n", ' ', var_export($_expected, true)));
    }
}

function section($_title) {
    echo "\n" . $_title . "\n" . str_repeat('-', mb_strlen($_title)) . "\n";
}

/* L'état des scènes d'un équipement, lu et écrit comme le ferait le cache du
 * coeur : pour vieillir une entrée plutôt que d'attendre. */
function sceneSetT($_eq, $_values) {
    $key = 'wledbe::scene::' . $_eq->getId();
    $state = isset(cache::$store[$key]) ? cache::$store[$key] : array();
    foreach ($_values as $k => $v) {
        if ($v === null) { unset($state[$k]); } else { $state[$k] = $v; }
    }
    cache::$store[$key] = $state;
}
function sceneGetT($_eq, $_key) {
    $state = $_eq->sceneState();
    return isset($state[$_key]) ? $state[$_key] : null;
}

function fixture($_name) {
    return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $_name), true);
}

$si = fixture('strip_json_si.json');
$eff = fixture('strip_json_eff.json');
$pal = fixture('strip_json_pal.json');
$fxdata = fixture('strip_json_fxdata.json');

/* ------------------------------------------------------------------------ */
section('Identification (/json/info)');

check('reconnu comme WLED', wledbe::looksLikeWled($si['info']), true);
check('un HomeWizard n\'est pas un WLED', wledbe::looksLikeWled(array('product_type' => 'HWE-P1', 'serial' => 'x')), false);
$d = wledbe::describe($si['info'], '192.168.1.50');
check('MAC normalisée', $d['mac'], 'aabbcc112233');
check('nom', $d['name'], 'Meuble salle a mnger');
check('version', $d['version'], '16.0.0');
check('nombre de LED', $d['leds'], 240);
check('bande (pas de leds.matrix)', $d['layout'], 'strip');
$matrixInfo = $si['info'];
$matrixInfo['leds']['matrix'] = array('w' => 16, 'h' => 16);
$m = wledbe::describe($matrixInfo, '192.168.1.51');
check('matrice détectée', $m['layout'], 'matrix');
check('taille de matrice', $m['matrix_w'] . 'x' . $m['matrix_h'], '16x16');
check('MAC avec deux-points', wledbe::normalizeMac('AA:BB:CC:44:55:9F'), 'aabbcc44559f');
check('MAC tronquée refusée', wledbe::normalizeMac('3494548317'), '');

/* ------------------------------------------------------------------------ */
section('Découverte mDNS (avahi-browse -rtp)');

$avahi = array(
    '+;ens18;IPv4;wled-44559f;_wled._tcp;local',
    '=;ens18;IPv4;wled-44559f;_wled._tcp;local;wled-44559f.local;192.168.1.50;80;"mac=aabbcc44559f"',
    '=;ens18;IPv6;wled-44559f;_wled._tcp;local;wled-44559f.local;fe80::1;80;"mac=aabbcc44559f"',
    '=;wlan0;IPv4;wled-44559f;_wled._tcp;local;wled-44559f.local;192.168.1.50;80;"mac=aabbcc44559f"',
    '=;ens18;IPv4;Matrice\032bureau;_wled._tcp;local;matrice.local;192.168.1.60;80;',
);
$found = wledbe::parseAvahi($avahi);
check('deux appareils (IPv6 et doublon d\'interface écartés)', count($found), 2);
check('adresse du premier', $found[0]['ip'], '192.168.1.50');
check('MAC lue dans le TXT', $found[0]['mac'], 'aabbcc44559f');
check('appareil sans TXT : MAC vide', $found[1]['mac'], '');

/* ------------------------------------------------------------------------ */
section('Listes d\'effets, palettes, presets');

$strip = wledbe::effectList($eff, $fxdata, false);
$matrix = wledbe::effectList($eff, $fxdata, true);
check('Solid en tête de liste', array_key_first($strip), 0);
check('RSVD écarté', in_array('RSVD', $strip, true), false);
check('Scrolling Text (2D) absent d\'une bande', in_array('Scrolling Text', $strip, true), false);
check('Scrolling Text présent sur une matrice', in_array('Scrolling Text', $matrix, true), true);
check('Chase 2 (1D) présent sur une bande', $strip[37] ?? null, 'Chase 2');
check('effet sonore marqué d\'une note', isset($strip[array_search('Gravimeter ♪', $strip, true)]), true);
check('plus d\'effets sur une matrice', count($matrix) > count($strip), true);
check('sans fxdata, tout effet non réservé est gardé', count(wledbe::effectList(array('Solid', 'RSVD', 'Blink'), array(), false)), 2);

$presets = wledbe::presetList(array(
    '0' => array(),
    '1' => array('n' => 'Police', 'on' => true),
    '3' => array('on' => true),
    '2' => array('n' => 'Alarme', 'playlist' => array('ps' => array(1, 3))),
));
check('preset 0 (vide) écarté, tri par numéro', array_keys($presets), array(1, 2, 3));
check('preset sans nom', $presets[3], 'Preset 3');
check('playlist signalée', $presets[2], 'Alarme (playlist)');
check('liste vide sur un WLED sans preset', wledbe::presetList(fixture('strip_presets_json.json')), array());
check('séparateurs neutralisés dans listValue', wledbe::listValue(array(4 => 'Rouge;bleu|vert')), '4|Rouge,bleu/vert');

/* ------------------------------------------------------------------------ */
section('Valeurs publiées (/json/si)');

$values = wledbe::stateValues($si['state'], $si['info'], $eff, $pal);
check('éteint', $values['on'], 0);
check('luminosité 128 → 50 %', $values['brightness'], 50);
check('couleur du segment principal', $values['color'], '#ffa000');
check('effet par son nom', $values['effect'], 'Solid');
check('palette par son nom', $values['palette'], 'Default');
check('signal Wi-Fi', $values['wifi_signal'], 22);
check('pas de preset actif', $values['preset'], -1);
check('effet inconnu : numéro affiché', wledbe::stateValues(array('seg' => array(array('id' => 0, 'fx' => 999))), array(), $eff, $pal)['effect'], '#999');
check('RGBW en blanc pur', wledbe::colorToHex(array(0, 0, 0, 255)), '#ffffff');
check('#f00 → rouge', wledbe::hexToColor('#f00'), array(255, 0, 0));
check('hexa RGBW', wledbe::hexToColor('00000080'), array(0, 0, 0, 128));

/* ------------------------------------------------------------------------ */
section('Vérification des ordres');

$state = $si['state'];
check('allumer, relu éteint : écart', count(wledbe::mismatches(array('on' => true), $state)), 1);
check('éteindre, relu éteint : conforme', wledbe::mismatches(array('on' => false), $state), array());
check('luminosité conforme', wledbe::mismatches(array('bri' => 128), $state), array());
check('bri 0 = extinction, conforme si éteint', wledbe::mismatches(array('bri' => 0), $state), array());
check('clés de transition ignorées', wledbe::mismatches(array('transition' => 20, 'v' => true, 'tt' => 5), $state), array());
check('effet conforme (seg objet, segments sélectionnés)', wledbe::mismatches(array('seg' => array('fx' => 0)), $state), array());
check('effet différent : écart', count(wledbe::mismatches(array('seg' => array('fx' => 37)), $state)), 1);
check('couleur conforme', wledbe::mismatches(array('seg' => array('col' => array(array(255, 160, 0)))), $state), array());
check('couleur en hexa conforme', wledbe::mismatches(array('seg' => array('col' => array('FFA000'))), $state), array());
check('couleur différente : écart', count(wledbe::mismatches(array('seg' => array('col' => array(array(255, 0, 0)))), $state)), 1);
check('canal blanc ignoré sur une bande RGB', wledbe::mismatches(array('seg' => array('col' => array(array(255, 160, 0, 0)))), $state), array());
check('seg liste, par rang', wledbe::mismatches(array('seg' => array(array('sx' => 128))), $state), array());
check('segment absent : écart', count(wledbe::mismatches(array('seg' => array(array('id' => 3, 'fx' => 1))), $state)), 1);
check('suppression de segment non vérifiée', wledbe::mismatches(array('seg' => array(array('id' => 3, 'stop' => 0))), $state), array());
check('valeur relative non vérifiée', wledbe::mismatches(array('bri' => '~10'), $state), array());
check('imbriqué (nl.dur) conforme', wledbe::mismatches(array('nl' => array('dur' => 60)), $state), array());
check('clé inconnue de l\'état ignorée', wledbe::mismatches(array('foo' => 1), $state), array());
check('ordre absolu : relançable', wledbe::isIdempotent(array('on' => true, 'seg' => array('fx' => 3))), true);
check('ordre relatif : envoyé une seule fois', wledbe::isIdempotent(array('seg' => array('sx' => '~10'))), false);
check('basculer : envoyé une seule fois', wledbe::isIdempotent(array('on' => 't')), false);

/* Sélection explicite : seul le segment 1 est sélectionné, c'est lui qui
 * doit porter l'effet. */
$two = array('on' => true, 'seg' => array(
    array('id' => 0, 'sel' => false, 'fx' => 0),
    array('id' => 1, 'sel' => true, 'fx' => 9),
));
check('seg objet : seuls les segments sélectionnés comptent', wledbe::mismatches(array('seg' => array('fx' => 9)), $two), array());

/* ------------------------------------------------------------------------ */
section('Envoi vérifié, sur un WLED simulé');

/* Un WLED qui perd le premier ordre (répond comme si rien n'avait changé),
 * puis l'applique au deuxième envoi. */
class wledbeFake extends wledbe {
    /* Les WLED simulés, par adresse : l'envoi parallèle des groupes les
     * retrouve ici au lieu de passer par le réseau. */
    public static $byIp = array();

    protected static function multiPost($_requests, $_connectMs, $_totalMs) {
        $out = array();
        foreach ($_requests as $key => $request) {
            $ip = parse_url($request['url'], PHP_URL_HOST);
            try {
                $out[$key] = isset(self::$byIp[$ip]) ? json_encode(self::$byIp[$ip]->call('POST', '/json/state', json_decode($request['body'], true))) : null;
            } catch (Throwable $e) {
                $out[$key] = null;
            }
        }
        return $out;
    }

    public $state;
    public $posts = 0;
    public $lose = 0;
    public $down = 0;
    public function call($_method, $_path, $_body = null) {
        if ($this->down > 0) {
            $this->down--;
            throw new Exception('Timeout');
        }
        if ($_method === 'POST') {
            $this->posts++;
            if ($this->lose > 0) {
                $this->lose--;
                return $this->state;
            }
            foreach ($_body as $k => $v) {
                if ($k === 'seg') {
                    /* Objet : segment principal ; liste : chaque segment par id. */
                    $orders = (array_keys($v) === range(0, count($v) - 1)) ? $v : array(array('id' => 0) + $v);
                    foreach ($orders as $order) {
                        $id = isset($order['id']) ? (int) $order['id'] : 0;
                        foreach ($order as $sk => $sv) {
                            if ($sk === 'n' && $sv === '') {
                                unset($this->state['seg'][$id]['n']);
                            } else {
                                $this->state['seg'][$id][$sk] = $sv;
                            }
                        }
                    }
                } elseif (!in_array($k, array('v', 'tt'), true)) {
                    $this->state[$k] = $v;
                }
            }
        }
        return $this->state;
    }
}

config::$values['wledbe::tries'] = 3;
$fake = new wledbeFake();
$fake->configuration = array('ip' => '192.168.1.50', 'verify' => 1);
$fake->createCommands();
$fake->state = $si['state'];
$fake->sendState(array('on' => true, 'seg' => array('fx' => 37)));
check('ordre appliqué du premier coup : un seul envoi', $fake->posts, 1);
check('état publié', $fake->published['on'] ?? null, 1);
check('effet publié', $fake->published['effect_id'] ?? null, 37);
check('vérification OK', $fake->published['verify_ok'] ?? null, 1);

$fake->posts = 0;
$fake->lose = 1;
$fake->sendState(array('bri' => 255));
check('ordre perdu une fois : renvoyé, deux envois', $fake->posts, 2);
check('détail : OK après 2 essais', substr($fake->published['verify_detail'], 9), 'OK après 2 essais');

$fake->posts = 0;
$fake->down = 1;
$fake->sendState(array('bri' => 200));
check('appareil muet une fois : ordre passé au second essai', $fake->posts, 1);
check('de nouveau en ligne', $fake->published['online'] ?? null, 1);

$fake->posts = 0;
$fake->lose = 10;
$error = '';
try {
    $fake->sendState(array('seg' => array('fx' => 9)));
} catch (Exception $e) {
    $error = $e->getMessage();
}
check('ordre toujours perdu : trois envois', $fake->posts, 3);
check('ordre toujours perdu : exception', strpos($error, 'non appliqué') !== false, true);
check('ordre toujours perdu : vérification en échec', $fake->published['verify_ok'] ?? null, 0);
check('message dans le centre de messages', count(message::$added), 1);

$fake->posts = 0;
$fake->lose = 10;
try {
    $fake->sendState(array('seg' => array('sx' => '~10')));
} catch (Exception $e) {
}
check('ordre relatif : jamais renvoyé', $fake->posts, 1);


/* ------------------------------------------------------------------------ */
section('Scènes : bibliothèque et options');

$scenes = wledbe::scenes();
check('scènes livrées', count($scenes), 6);
check('Police : recette bande Chase 2', wledbe::findScene('police')['strip']['effect'], 'Chase 2');
check('recherche par nom, sans égard à la casse', wledbe::findScene('ALARME INTRUSION')['id'], 'alarme');
$err = '';
try { wledbe::findScene('Disco'); } catch (Exception $e) { $err = $e->getMessage(); }
check('scène inconnue : message qui liste les scènes', strpos($err, 'Police') !== false, true);
check('identifiant tiré du nom', wledbe::normalizeScene(array('name' => 'Fuite d\'eau cave'))['id'], 'fuite_d_eau_cave');
check('priorité bornée', wledbe::normalizeScene(array('name' => 'x', 'priority' => 250))['priority'], 100);
check('couleur invalide remplacée', wledbe::normalizeScene(array('name' => 'x', 'strip' => array('colors' => array('rouge'))))['strip']['colors'][0], '#ffffff');

$now = mktime(21, 0, 0, 9, 25, 2026);
check('« 30 » seul = durée', wledbe::parseSceneOptions('30'), array('duration' => 30));
check('durée=5m', wledbe::parseSceneOptions('durée=5m')['duration'], 300);
check('duree=1,5h', wledbe::parseSceneOptions('duree=1,5h')['duration'], 5400);
check('durée=infini', wledbe::parseSceneOptions('durée=infini')['duration'], 0);
check('délai=10 priorité=95 fin=éteindre', wledbe::parseSceneOptions('délai=10 priorité=95 fin=éteindre'),
    array('delay' => 10, 'priority' => 95, 'end' => 'off'));
check('heure=22:30 → dans 90 minutes', wledbe::parseSceneOptions('heure=22:30', $now)['delay'], 5400);
check('heure passée → demain', wledbe::parseSceneOptions('heure=20h00', $now)['delay'], 23 * 3600);
$err = '';
try { wledbe::parseSceneOptions('couleur=rouge'); } catch (Exception $e) { $err = $e->getMessage(); }
check('option inconnue refusée', strpos($err, 'inconnue') !== false, true);

/* ------------------------------------------------------------------------ */
section('Scènes : ordres WLED');

$strip = new wledbeFake();
$strip->id = 50;
$strip->configuration = array('ip' => '192.168.1.50', 'verify' => 1, 'layout' => 'strip');
$strip->setCache('fx_names', $eff);
$strip->setCache('pal_names', $pal);
$frag = $strip->sceneFragment(wledbe::findScene('police'), $si['state']);
check('Police : effet résolu par son nom', $frag['seg'][0]['fx'], 37);
check('Police : rouge puis bleu', $frag['seg'][0]['col'], array(array(255, 0, 0), array(0, 0, 255), array(0, 0, 0)));
check('sans fondu', $frag['tt'], 0);
check('tous les segments visés, par id', $frag['seg'][0]['id'], 0);
check('pas de texte sur une bande', isset($frag['seg'][0]['n']), false);

$matrixEq = new wledbeFake();
$matrixEq->id = 51;
$matrixEq->configuration = array('ip' => '192.168.1.51', 'layout' => 'matrix');
$matrixEq->setCache('fx_names', $eff);
$mfrag = $matrixEq->sceneFragment(wledbe::findScene('alarme'), $si['state']);
check('matrice : texte défilant', $mfrag['seg'][0]['fx'] . ' ' . $mfrag['seg'][0]['n'], '122 ALARME');
check('matrice sans recette propre : recette bande', $matrixEq->sceneFragment(wledbe::findScene('police'), $si['state'])['seg'][0]['fx'], 37);

$custom = wledbe::normalizeScene(array('name' => 'Perso', 'strip' => array('effect' => 'Rainbow', 'palette' => 'Party', 'json' => '{"seg":{"c1":200},"transition":0}')));
$cfrag = $strip->sceneFragment($custom, $si['state']);
check('palette par son nom', $cfrag['seg'][0]['pal'], array_search('Party', $pal, true));
check('JSON avancé fusionné dans chaque segment', $cfrag['seg'][0]['c1'], 200);
check('JSON avancé : clé de premier niveau', $cfrag['transition'], 0);
$err = '';
try { $strip->sceneFragment(wledbe::normalizeScene(array('name' => 'x', 'strip' => array('effect' => 'Police'))), $si['state']); } catch (Exception $e) { $err = $e->getMessage(); }
check('effet absent de ce WLED : erreur claire', strpos($err, 'inconnu sur ce WLED') !== false, true);

$restore = wledbe::restoreFragment($si['state']);
check('restauration : état et luminosité', array($restore['on'], $restore['bri']), array(false, 128));
check('restauration : aspect du segment', $restore['seg'][0]['col'], array(array(255, 160, 0), array(0, 0, 0), array(0, 0, 0)));
check('restauration : nom effacé', $restore['seg'][0]['n'], '');
check('restauration : géométrie jamais touchée', isset($restore['seg'][0]['start']), false);
check('playlist en cours : relancée', wledbe::restoreFragment(array('on' => true, 'pl' => 4, 'ps' => 2)), array('ps' => 4));
check('« ps » d\'une playlist vérifié par « pl »', wledbe::mismatches(array('ps' => 4), array('ps' => 2, 'pl' => 4)), array());

/* ------------------------------------------------------------------------ */
section('Scènes : pile de priorités');

$strip->state = $si['state'];
$strip->createCommands();
$original = $strip->state;

$strip->startScene('sonnette', array('duration' => 0));
check('sonnette affichée', $strip->state['seg'][0]['fx'], 1);
check('info « Scène en cours »', $strip->published['scene'], 'Sonnette');
$strip->startScene('police', array('duration' => 0));
check('police (90) recouvre la sonnette (40)', $strip->state['seg'][0]['fx'], 37);
$strip->startScene('notification', array('duration' => 0));
check('notification (20) ne recouvre pas la police', $strip->state['seg'][0]['fx'], 37);
check('trois scènes dans la pile', count($strip->stack()), 3);
check('prochain réveil : aucun (scènes sans fin, sans garde)', $strip->nextWake(), null);
$strip->stopScenes(false);
check('police arrêtée : la sonnette reprend', $strip->state['seg'][0]['fx'], 1);
$strip->stopScenes(false);
check('sonnette arrêtée : la notification reprend', $strip->published['scene'], 'Notification');
$strip->stopScenes(false);
check('pile vide : éclairage d\'avant rendu', wledbe::mismatches(wledbe::restoreFragment($original), $strip->state), array());
check('pile vide : éteint comme avant', $strip->state['on'], false);
check('info « Scène en cours » vidée', $strip->published['scene'], '');

$strip->startScene('police', array('duration' => 0, 'end' => 'off'));
$strip->state['seg'][0]['col'][0] = array(1, 2, 3);
$strip->stopScenes(false);
check('fin=éteindre : éteint', $strip->state['on'], false);

/* Durée écoulée : tick() retire la scène. On vieillit l'entrée plutôt que
 * d'attendre. */
$strip->state = $original;
$strip->startScene('sonnette', array('duration' => 10));
check('réveil prévu à la fin de la sonnette', $strip->nextWake() - time() <= 10, true);
$stack = $strip->stack();
$stack[0]['until'] = time() - 1;
sceneSetT($strip, array('stack' => $stack));
$strip->tick();
check('durée écoulée : scène retirée', count($strip->stack()), 0);
check('durée écoulée : éclairage rendu', $strip->state['seg'][0]['fx'], 0);

/* Délai : la scène attend son heure. */
$strip->startScene('police', array('delay' => 60, 'duration' => 5));
check('scène programmée : rien d\'affiché', $strip->state['seg'][0]['fx'], 0);
check('réveil à l\'heure de départ', abs($strip->nextWake() - (time() + 60)) <= 1, true);
$stack = $strip->stack();
$stack[0]['start_at'] = time() - 1;
sceneSetT($strip, array('stack' => $stack));
$strip->tick();
check('heure venue : scène lancée', $strip->state['seg'][0]['fx'], 37);
$strip->stopScenes(true);

/* Garde : l'alarme défaite est réimposée. */
$strip->state = $original;
$strip->startScene('alarme', array('duration' => 0));
check('alarme affichée', $strip->state['seg'][0]['fx'], array_search('Strobe Mega', $eff, true));
check('sous garde : réveil prévu', $strip->nextWake() !== null, true);
$strip->state['on'] = false;
sceneSetT($strip, array('guard_at' => time() - 1));
$strip->tick();
check('alarme éteinte par quelqu\'un : rallumée par la garde', $strip->state['on'], true);

/* Commande manuelle : l'utilisateur reprend la main. */
$strip->runAction('off_set', array());
check('commande manuelle : scènes abandonnées', count($strip->stack()), 0);
check('commande manuelle : rien restauré, reste éteint', $strip->state['on'], false);

/* Restauration impossible : retentée plus tard. */
$strip->state = $original;
$strip->startScene('sonnette', array('duration' => 0));
$strip->down = 20;
$strip->stopScenes(false);
check('WLED muet à la fin : restauration en attente', is_array(sceneGetT($strip, 'pending_restore')), true);
$strip->down = 0;
sceneSetT($strip, array('restore_at' => time() - 1));
$strip->tick();
check('restauration retentée au réveil suivant', $strip->state['seg'][0]['fx'], 0);
check('plus rien en attente', sceneGetT($strip, 'pending_restore'), null);

/* Commandes de scène. */
$sceneCmds = array_filter($strip->getCmd('action'), function ($c) { return strpos($c->logicalId, 'scene::') === 0; });
check('une commande par scène', count($sceneCmds), 6);
$strip->syncSceneCommands(array(wledbe::normalizeScene(array('id' => 'police', 'name' => 'Gyrophare'))));
$sceneCmds = array_values(array_filter($strip->getCmd('action'), function ($c) { return strpos($c->logicalId, 'scene::') === 0; }));
check('scènes supprimées : commandes retirées', count($sceneCmds), 1);
check('scène renommée : commande renommée', $sceneCmds[0]->name, 'Scène Gyrophare');


/* ------------------------------------------------------------------------ */
section('Relecture : restauration, pile et garde');

/* Une nouvelle scène pendant une restauration en souffrance garde la
 * photographie d'origine : à sa fin, c'est l'éclairage d'avant la police
 * qui revient, pas la police. */
$r = new wledbeFake();
$r->id = 60;
$r->configuration = array('ip' => '192.168.1.60', 'verify' => 1, 'layout' => 'strip');
$r->setCache('fx_names', $eff);
$r->state = $si['state'];
$r->createCommands();
$orig = $r->state;
$r->startScene('police', array('duration' => 0));
$r->down = 20;
$r->stopScenes(false);
$r->down = 0;
check('restauration en souffrance', is_array(sceneGetT($r, 'pending_restore')), true);
$r->startScene('sonnette', array('duration' => 0));
$r->stopScenes(false);
check('scène suivante finie : éclairage d\'avant la police', wledbe::mismatches(wledbe::restoreFragment($orig), $r->state), array());

/* Une commande manuelle annule une restauration en souffrance. */
$r->state = $orig;
$r->startScene('police', array('duration' => 0));
$r->down = 20;
$r->stopScenes(false);
$r->down = 0;
$r->runAction('on_set', array());
check('commande manuelle : restauration abandonnée', sceneGetT($r, 'pending_restore'), null);
sceneSetT($r, array('restore_at' => time() - 1));
$r->tick();
check('et le réveil suivant ne défait pas la commande', $r->state['on'], true);

/* Restauration en souffrance et scène programmée : le réveil ne reste pas
 * dans le passé, et la restauration a lieu quand même. */
$r->state = $orig;
$r->startScene('police', array('duration' => 0));
$r->down = 20;
$r->stopScenes(false);
$r->down = 0;
$r->startScene('sonnette', array('delay' => 3600));
sceneSetT($r, array('restore_at' => time() - 1));
$r->tick();
check('restauration faite malgré la scène programmée', sceneGetT($r, 'pending_restore'), null);
check('réveil suivant : l\'heure de la scène programmée', abs($r->nextWake() - (time() + 3600)) <= 2, true);
$r->stopScenes(true);

/* Échec d'envoi à l'affichage : la scène n'est pas marquée affichée, et un
 * réveil est prévu pour réessayer, dans le futur. */
$r->state = $orig;
$r->down = 20;
$err = '';
try { $r->startScene('police', array('duration' => 0)); } catch (Exception $e) { $err = $e->getMessage(); }
$r->down = 0;
check('WLED muet : erreur rendue au scénario', $err !== '', true);
check('WLED muet : scène pas marquée affichée', (string) sceneGetT($r, 'applied_key'), '');
check('WLED muet : nouvel essai prévu, pas dans le passé', $r->nextWake() > time(), true);
sceneSetT($r, array('retry_at' => time() - 1));
$r->tick();
check('au réveil, la scène est affichée', $r->state['seg'][0]['fx'], 37);
$r->stopScenes(true);

/* Scène injouable (effet absent) sous garde : retirée, sans boucle. */
$bad = wledbe::normalizeScene(array('id' => 'bad', 'name' => 'Cassée', 'guard' => 1, 'duration' => 0, 'strip' => array('effect' => 'Inexistant')));
$err = '';
try { $r->playScene($bad); } catch (Exception $e) { $err = $e->getMessage(); }
check('scène injouable : erreur claire', strpos($err, 'inconnu sur ce WLED') !== false, true);
check('scène injouable : retirée de la pile', count($r->stack()), 0);
check('scène injouable : aucun réveil', $r->nextWake(), null);

/* Scène injouable par-dessus une autre : la précédente revient. */
$r->state = $orig;
$r->startScene('sonnette', array('duration' => 0));
try { $r->playScene(wledbe::normalizeScene(array('id' => 'bad', 'name' => 'Cassée', 'priority' => 99, 'strip' => array('effect' => 'Inexistant')))); } catch (Exception $e) {}
check('scène injouable : la sonnette reste affichée', $r->state['seg'][0]['fx'], 1);
$r->stopScenes(true);

/* Scène supprimée de la bibliothèque pendant qu'elle attend sous une autre. */
$r->state = $orig;
$r->startScene('notification', array('duration' => 0));
$r->startScene('police', array('duration' => 0));
$r->purgeScenes(array('police' => 1));
check('scène supprimée : retirée de la pile', array_column($r->stack(), 'key'), array('police'));
$r->stopScenes(false);
check('puis éclairage d\'avant', wledbe::mismatches(wledbe::restoreFragment($orig), $r->state), array());

/* Relancer avec délai la scène affichée : l'appareil n'en reste pas figé. */
$r->state = $orig;
$r->startScene('police', array('duration' => 0));
$r->startScene('police', array('delay' => 600));
check('relance différée : éclairage rendu pendant l\'attente', $r->state['seg'][0]['fx'], 0);
check('info « Scène en cours » vide pendant l\'attente', $r->published['scene'], '');
$r->stopScenes(true);

/* Commande manuelle : les scènes programmées restent prévues. */
$r->state = $orig;
$r->startScene('sonnette', array('delay' => 600));
$r->startScene('police', array('duration' => 0));
$r->runAction('off_set', array());
check('commande manuelle : la scène programmée reste', array_column($r->stack(), 'key'), array('sonnette'));
$r->stopScenes(true);

/* Deux scènes qui finissent ensemble : le mode de fin de celle affichée. */
$r->state = $orig;
$r->startScene('notification', array('duration' => 10, 'end' => 'off'));
$r->startScene('police', array('duration' => 10, 'end' => 'restore'));
$st = $r->stack();
foreach ($st as $i => $e) { $st[$i]['until'] = time() - 1; }
sceneSetT($r, array('stack' => $st));
$r->state['on'] = true;
$r->tick();
check('fin simultanée : restauration (mode de la police affichée)', wledbe::mismatches(wledbe::restoreFragment($orig), $r->state), array());

/* Verrou : un appel imbriqué dans le même processus ne se bloque pas. */
$m = new ReflectionMethod('wledbe', 'withLock');
$m->setAccessible(true);
$inner = $m->invoke($r, function () use ($m, $r) { return $m->invoke($r, function () { return 'imbriqué'; }); });
check('verrou réentrant', $inner, 'imbriqué');

/* ------------------------------------------------------------------------ */
section('Relecture : vérification et options');

check('« w~10 » est relatif', wledbe::isIdempotent(array('bri' => 'w~10')), false);
check('« 1~5~ » est relatif', wledbe::isIdempotent(array('ps' => '1~5~')), false);
check('segment bri 0 = segment éteint', wledbe::mismatches(array('seg' => array(array('id' => 0, 'bri' => 0))), array('seg' => array(array('id' => 0, 'on' => false, 'bri' => 255)))), array());
check('sans segment sélectionné : le principal', wledbe::mismatches(array('seg' => array('fx' => 5)),
    array('mainseg' => 1, 'seg' => array(array('id' => 0, 'fx' => 0), array('id' => 1, 'fx' => 5)))), array());
check('couleur relue illisible : écart, pas d\'exception', count(wledbe::mismatches(array('seg' => array('col' => array(array(1, 2, 3)))), array('seg' => array(array('id' => 0, 'sel' => true, 'col' => array('zz')))))), 1);
$diff = wledbe::mismatches(array('seg' => array('n' => 'x')), array('seg' => array(array('id' => 0, 'sel' => true, 'n' => '<img src=x>'))));
check('valeur relue sans balisage dans le message', strpos($diff[0], '<') === false, true);
check('« DURÉE=30 » en majuscules', wledbe::parseSceneOptions('DURÉE=30')['duration'], 30);
check('« durée=5 min » avec une espace', wledbe::parseSceneOptions('durée=5 min')['duration'], 300);
check('durée vidée dans l\'éditeur : valeur par défaut, pas « sans fin »', wledbe::normalizeScene(array('name' => 'x', 'duration' => ''))['duration'], 60);
check('luminosité vidée : 100 %, pas 1 %', wledbe::normalizeScene(array('name' => 'x', 'strip' => array('brightness' => '')))['strip']['brightness'], 100);
$long = wledbe::normalizeRecipe(array('text' => str_repeat('É', 20)), true)['text'];
check('texte défilant coupé à 32 octets', strlen($long) <= 32, true);
check('sur une frontière de caractère', mb_check_encoding($long, 'UTF-8'), true);
check('nom de scène sans balisage', wledbe::normalizeScene(array('name' => '<b>Alarme</b>'))['name'], 'Alarme');

config::save('scenes', '[]', 'wledbe');
check('bibliothèque vidée : reste vide', wledbe::scenes(), array());
config::save('scenes', '', 'wledbe');
check('bibliothèque jamais touchée : scènes livrées', count(wledbe::scenes()), 6);

/* ------------------------------------------------------------------------ */
section('Relecture : identité et découverte');

$id = new wledbeFake();
$id->id = 70;
$id->configuration = array('ip' => '192.168.1.70', 'mac' => 'aabbcc000070');
$id->createCommands();
$foreign = $si;
$foreign['info']['mac'] = 'aabbcc999999';
$foreign['info']['name'] = 'Intrus';
$id->ingest($foreign);
check('autre WLED à la même IP : identité gardée', $id->configuration['mac'] . ' / ' . ($id->configuration['device_name'] ?? ''), 'aabbcc000070 / ');
check('autre WLED à la même IP : compté comme échec', (int) $id->getCache('failures', 0), 1);
check('MAC d\'un équipement créé à la main : apprise au relevé', (function () use ($si) {
    $e = new wledbeFake(); $e->id = 71; $e->configuration = array('ip' => '192.168.1.71');
    $e->applyInfo(wledbe::describe($si['info'], '192.168.1.71'));
    return $e->configuration['mac'];
})(), 'aabbcc112233');
$evil = $si['info'];
$evil['name'] = '<img src=x onerror=alert(1)>Salon';
check('nom d\'appareil sans balisage', wledbe::describe($evil, '1.2.3.4')['name'], 'Salon');
check('sous-réseau /24', wledbe::subnetPrefix('192.168.20.0/24'), '192.168.20');
check('sous-réseau sans masque', wledbe::subnetPrefix('10.0.5'), '10.0.5');
$err = '';
try { wledbe::subnetPrefix('10.0.0.0/16'); } catch (Exception $e) { $err = $e->getMessage(); }
check('/16 refusé, pas tronqué en silence', strpos($err, '/24') !== false, true);
$err = '';
try { wledbe::subnetPrefix('300.1.1'); } catch (Exception $e) { $err = $e->getMessage(); }
check('octet > 255 refusé', $err !== '', true);
$sceneCmd = new wledbeCmd();
$sceneCmd->logicalId = 'scene::police';
check('commande de scène protégée de la sauvegarde de page', $sceneCmd->dontRemoveCmd(), true);


/* ------------------------------------------------------------------------ */
section('V3 : groupes');

/* Un groupe simulé, dont les membres sont donnés directement. */
class wledbeFakeGroup extends wledbeFake {
    public $fakeMembers = array();
    public function members() { return $this->fakeMembers; }
}

check('membres en texte « 12,34 »', wledbe::parseMembers('12, 34,12'), array(12, 34));
check('membres en liste', wledbe::parseMembers(array('5', 0, 'x', 7)), array(5, 7));
check('membres vides', wledbe::parseMembers(''), array());

$fxOld = $eff;                                  /* numérotation 16.0 */
$fxNew = array_merge(array('Solid', 'Nouveau'), array_slice($eff, 1));  /* numéros décalés d'un cran */
$a = new wledbeFake(); $a->id = 80; $a->configuration = array('ip' => '10.0.0.80', 'verify' => 1, 'layout' => 'strip');
$b = new wledbeFake(); $b->id = 81; $b->configuration = array('ip' => '10.0.0.81', 'verify' => 1, 'layout' => 'matrix');
$a->setCache('fx_names', $fxOld); $b->setCache('fx_names', $fxNew);
$a->setCache('pal_names', $pal);  $b->setCache('pal_names', $pal);
$a->state = $si['state']; $b->state = $si['state'];
$a->createCommands(); $b->createCommands();
wledbeFake::$byIp = array('10.0.0.80' => $a, '10.0.0.81' => $b);

$g = new wledbeFakeGroup(); $g->id = 90;
$g->configuration = array('kind' => 'group', 'members' => array(80, 81));
$g->fakeMembers = array($a, $b);
check('un groupe est un groupe', $g->isGroup(), true);
check('un groupe n\'est pas relevé', $g->isConfigured(), false);
$g->createCommands();
check('groupe : commande « Membres en ligne »', is_object($g->getCmd('info', 'members_online')), true);
check('groupe : pas de commande de preset', $g->getCmd('action', 'preset_set'), null);

$a->posts = 0; $b->posts = 0;
$g->runAction('on_set', array());
check('allumer le groupe : les deux membres allumés', array($a->state['on'], $b->state['on']), array(true, true));
check('un seul envoi par membre (parallèle, vérifié du premier coup)', array($a->posts, $b->posts), array(1, 1));
check('vérification du groupe OK', $g->published['verify_ok'], 1);

$g->runAction('effect_set', array('select' => 'Chase 2'));
check('effet par nom : numéro propre à chaque membre', array($a->state['seg'][0]['fx'], $b->state['seg'][0]['fx']), array(37, 38));

/* Les listes des membres, telles que refreshLists() les écrit : filtrées
 * selon le type (pas d'effet 2D sur la bande). */
$a->getCmd('action', 'effect_set')->setConfiguration('listValue', wledbe::listValue(wledbe::effectList($fxOld, $fxdata, false)));
$b->getCmd('action', 'effect_set')->setConfiguration('listValue', wledbe::listValue(wledbe::effectList($fxNew, array(), true)));
$g->refreshGroupLists();
$list = $g->getCmd('action', 'effect_set')->configuration['listValue'];
check('liste du groupe : effets communs, par nom', strpos($list, 'Chase 2|Chase 2') !== false, true);
check('liste du groupe : un effet d\'un seul membre absent', strpos($list, 'Nouveau') === false, true);
check('liste du groupe : pas d\'effet 2D (la bande ne l\'a pas)', strpos($list, 'Scrolling Text') === false, true);
$c = new wledbeFake(); $c->id = 82; $c->configuration = array('ip' => '10.0.0.82', 'layout' => 'strip'); $c->createCommands();
$g->fakeMembers = array($a, $b, $c);
$g->refreshGroupLists();
check('membre sans liste lue : la liste du groupe reste remplie', strpos($g->getCmd('action', 'effect_set')->configuration['listValue'], 'Chase 2') !== false, true);
$g->fakeMembers = array($a, $b);

$a->state['on'] = false;
$g->runAction('toggle', array());
check('basculer avec un membre allumé : tout s\'éteint', array($a->state['on'], $b->state['on']), array(false, false));
$g->runAction('toggle', array());
check('basculer tout éteint : tout s\'allume', array($a->state['on'], $b->state['on']), array(true, true));

/* Un membre qui perd l'ordre : les autres sont servis, l'échec est dit. */
$b->lose = 10;
$err = '';
try { $g->runAction('off_set', array()); } catch (Exception $e) { $err = $e->getMessage(); }
$b->lose = 0;
check('membre en échec : l\'autre est servi', $a->state['on'], false);
check('membre en échec : erreur rendue', $err !== '', true);
check('membre en échec : vérification du groupe à 0', $g->published['verify_ok'], 0);

/* Scène sur le groupe : chaque membre la joue et la rend. */
$a->state = $si['state']; $b->state = $si['state'];
$g->runAction('scene::police', array());
check('scène de groupe : police sur les deux', array($a->state['seg'][0]['fx'], $b->state['seg'][0]['fx']), array(37, 38));
$g->runAction('scene_stop_all', array());
check('arrêt de groupe : éclairage rendu partout', array($a->state['seg'][0]['fx'], $b->state['seg'][0]['fx']), array(0, 0));

/* Texte : seules les matrices du groupe l'affichent. */
$g->runAction('text_show', array('title' => 'BONJOUR', 'message' => 'couleur=vert durée=10'));
check('texte de groupe : sur la matrice', $b->state['seg'][0]['n'] ?? '', 'BONJOUR');
check('texte de groupe : pas sur la bande', isset($a->state['seg'][0]['n']), false);
$b->stopScenes(true);

/* ------------------------------------------------------------------------ */
section('V3 : texte sur matrice');

check('couleur nommée', wledbe::parseColor('Rouge'), '#ff0000');
check('couleur hexadécimale', wledbe::parseColor('00FF80'), '#00ff80');
check('options de texte', wledbe::parseSceneOptions('couleur=bleu durée=20 vitesse=200', null, true),
    array('color' => '#0000ff', 'duration' => 20, 'speed' => 200));
$err = '';
try { wledbe::parseSceneOptions('couleur=bleu'); } catch (Exception $e) { $err = $e->getMessage(); }
check('« couleur » refusée pour une scène de la bibliothèque', $err !== '', true);
$b->state = $si['state'];
$b->showText('ALERTE', 'couleur=rouge durée=15');
check('texte : effet Scrolling Text', $b->state['seg'][0]['fx'], array_search('Scrolling Text', $fxNew, true));
check('texte : couleur', $b->state['seg'][0]['col'][0], array(255, 0, 0));
check('texte : durée', $b->stack()[0]['duration'], 15);
$b->stopScenes(true);
check('texte fini : nom de segment effacé', isset($b->state['seg'][0]['n']), false);
$err = '';
try { $a->showText('X'); } catch (Exception $e) { $err = $e->getMessage(); }
check('texte refusé sur une bande', strpos($err, 'matrice') !== false, true);
check('commande « Afficher un texte » sur la matrice', is_object($b->getCmd('action', 'text_show')), true);
check('pas sur la bande', $a->getCmd('action', 'text_show'), null);

/* ------------------------------------------------------------------------ */
section('V3 : état poussé et garde');

$a->state = $si['state'];
$a->startScene('alarme', array('duration' => 0));
sceneSetT($a, array('guard_at' => time() + 8, 'guard_ran' => time() - 60));
$pushed = array('state' => $a->state, 'info' => $si['info']);
$pushed['info']['mac'] = 'aabbcc112233';
$pushed['state']['on'] = false;
$a->configuration['mac'] = 'aabbcc112233';
$a->ingestLive($pushed);
check('état poussé : publié aussitôt', $a->published['on'], 0);
check('scène sous garde défaite : garde avancée à maintenant', (int) sceneGetT($a, 'guard_at') <= time(), true);
sceneSetT($a, array('guard_at' => time() + 8, 'guard_ran' => time()));
$a->ingestLive($pushed);
check('garde qui vient de tourner : pas relancée en boucle', (int) sceneGetT($a, 'guard_at') > time(), true);
$a->stopScenes(true);


/* ------------------------------------------------------------------------ */
section('Relecture V3');

/* Membres gardés en texte : la page du coeur les renvoie tels quels. */
$gs = new wledbeFakeGroup(); $gs->id = 91;
$gs->configuration = array('kind' => 'group', 'members' => array(3, 5));
$gs->preSave();
check('membres enregistrés en texte « 3,5 »', $gs->configuration['members'], '3,5');
$gs->configuration['members'] = '3,5';
$gs->preSave();
check('et relus à l\'identique', $gs->configuration['members'], '3,5');

/* Texte : accents translittérés, balises gardées, limite de 32. */
check('accents translittérés', wledbe::sceneText('Alerte école à 7h'), 'Alerte ecole a 7h');
check('« <3 » n\'est pas pris pour une balise', wledbe::sceneText('I <3 U'), 'I <3 U');
check('caractères hors ASCII retirés', wledbe::sceneText('Feu 🔥 !'), 'Feu  !');
check('coupé à 32 caractères', strlen(wledbe::sceneText(str_repeat('é', 40))), 32);
$err = '';
try { $b->showText('🔥🔥'); } catch (Exception $e) { $err = $e->getMessage(); }
check('texte vide une fois nettoyé : refusé', strpos($err, 'Aucun texte') !== false, true);

/* Le texte ne dépend pas de la bibliothèque. */
$b->state = $si['state'];
$b->showText('MATIN', 'heure=07:00');
$b->purgeScenes(array('police' => 1));
check('texte programmé : survit à l\'enregistrement des scènes', array_column($b->stack(), 'key'), array('_texte'));
$b->stopScenes(true);
$lib = wledbe::saveScenes(array(array('id' => '_texte', 'name' => 'Texte perso')));
check('identifiant réservé refusé à la bibliothèque', $lib[0]['id'], 'texte');
config::save('scenes', '', 'wledbe');

/* État poussé par un autre appareil : ignoré, garde comprise. */
$a->state = $si['state'];
$a->startScene('alarme', array('duration' => 0));
sceneSetT($a, array('guard_at' => time() + 8, 'guard_ran' => time() - 60));
$intrus = array('state' => array('on' => false, 'seg' => array()), 'info' => $si['info']);
$intrus['info']['mac'] = 'ffffffffffff';
$a->ingestLive($intrus);
check('état d\'un autre appareil : la garde ne bouge pas', (int) sceneGetT($a, 'guard_at') > time(), true);
$a->stopScenes(true);

/* Groupe : ordre relatif jamais renvoyé, membre hors ligne sans relances. */
$a->state = $si['state']; $b->state = $si['state'];
$a->posts = 0; $b->posts = 0;
$b->lose = 10;
try { $g->runAction('json_set', array('message' => '{"bri":"~10"}')); } catch (Exception $e) {}
$b->lose = 0;
check('ordre relatif perdu : pas renvoyé', $b->posts, 1);
$b->down = 50; $b->posts = 0;
$b->setCache('failures', wledbe::OFFLINE_AFTER);
$t0 = microtime(true);
$err = '';
try { $g->runAction('on_set', array()); } catch (Exception $e) { $err = $e->getMessage(); }
check('membre connu hors ligne : aucune relance', microtime(true) - $t0 < 1, true);
check('membre connu hors ligne : dit dans l\'erreur', strpos($err, 'hors ligne') !== false, true);
check('l\'autre membre servi', $a->state['on'], true);
$b->down = 0; $b->setCache('failures', 0);

/* ------------------------------------------------------------------------ */
section('Commandes');

$names = array();
$logical = array();
foreach (cmd::$table as $cmd) {
    if ($cmd->eqLogic_id != $fake->id) {
        continue;
    }
    $names[] = $cmd->name;
    $logical[$cmd->logicalId] = $cmd;
}
check('noms uniques', count($names), count(array_unique($names)));
check('« Allumer » reliée à l\'état', $logical['on_set']->value, $logical['on']->id);
check('liste d\'effets reliée au numéro d\'effet', $logical['effect_set']->value, $logical['effect_id']->id);
check('curseur de luminosité 0-100', $logical['brightness_set']->configuration['maxValue'], 100);
$before = count(cmd::$table);
$fake->createCommands();
check('création idempotente', count(cmd::$table), $before);

echo "\n" . $passed . ' réussi(s), ' . $failed . " échec(s)\n";
exit($failed > 0 ? 1 : 0);
