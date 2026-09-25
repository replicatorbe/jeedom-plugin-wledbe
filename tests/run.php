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
$strip->setCache('stack', $stack);
$strip->tick();
check('durée écoulée : scène retirée', count($strip->stack()), 0);
check('durée écoulée : éclairage rendu', $strip->state['seg'][0]['fx'], 0);

/* Délai : la scène attend son heure. */
$strip->startScene('police', array('delay' => 60, 'duration' => 5));
check('scène programmée : rien d\'affiché', $strip->state['seg'][0]['fx'], 0);
check('réveil à l\'heure de départ', abs($strip->nextWake() - (time() + 60)) <= 1, true);
$stack = $strip->stack();
$stack[0]['start_at'] = time() - 1;
$strip->setCache('stack', $stack);
$strip->tick();
check('heure venue : scène lancée', $strip->state['seg'][0]['fx'], 37);
$strip->stopScenes(true);

/* Garde : l'alarme défaite est réimposée. */
$strip->state = $original;
$strip->startScene('alarme', array('duration' => 0));
check('alarme affichée', $strip->state['seg'][0]['fx'], array_search('Strobe Mega', $eff, true));
check('sous garde : réveil prévu', $strip->nextWake() !== null, true);
$strip->state['on'] = false;
$strip->setCache('guard_at', time() - 1);
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
check('WLED muet à la fin : restauration en attente', is_array($strip->getCache('pending_restore', null)), true);
$strip->down = 0;
$strip->setCache('restore_at', time() - 1);
$strip->tick();
check('restauration retentée au réveil suivant', $strip->state['seg'][0]['fx'], 0);
check('plus rien en attente', $strip->getCache('pending_restore', null), null);

/* Commandes de scène. */
$sceneCmds = array_filter($strip->getCmd('action'), function ($c) { return strpos($c->logicalId, 'scene::') === 0; });
check('une commande par scène', count($sceneCmds), 6);
$strip->syncSceneCommands(array(wledbe::normalizeScene(array('id' => 'police', 'name' => 'Gyrophare'))));
$sceneCmds = array_values(array_filter($strip->getCmd('action'), function ($c) { return strpos($c->logicalId, 'scene::') === 0; }));
check('scènes supprimées : commandes retirées', count($sceneCmds), 1);
check('scène renommée : commande renommée', $sceneCmds[0]->name, 'Scène Gyrophare');

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
