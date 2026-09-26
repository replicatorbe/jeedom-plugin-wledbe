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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    function wledbeEq() {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'wledbe') {
            throw new Exception(__('Équipement introuvable :', __FILE__) . ' ' . init('id'));
        }
        return $eqLogic;
    }

    /* Recherche sur le réseau local (mDNS et balayage HTTP), sans rien créer. */
    if (init('action') == 'discover') {
        unautorizedInDemo();
        ajax::success(wledbe::discover(init('subnet')));
    }

    /* Ce qui répond à une adresse donnée, sans rien créer. */
    if (init('action') == 'probe') {
        unautorizedInDemo();
        $device = wledbe::probe(init('ip'));
        $existing = $device['mac'] !== '' ? wledbe::byLogicalId($device['mac'], 'wledbe') : null;
        $device['known'] = is_object($existing) ? $existing->getHumanName() : '';
        $device['known_ip'] = is_object($existing) ? (string) $existing->getConfiguration('ip') : '';
        $device['source'] = 'IP';
        ajax::success(array('devices' => array($device), 'mdns' => true, 'probe' => $device['ip']));
    }

    /* Crée les appareils retenus, ou met leur adresse à jour. */
    if (init('action') == 'create') {
        unautorizedInDemo();
        $devices = json_decode(init('devices'), true);
        if (!is_array($devices) || count($devices) === 0) {
            throw new Exception(__('Aucun appareil à créer.', __FILE__));
        }
        /* Un appareil qui échoue n'empêche pas les autres d'être créés : la
         * page dit lesquels, puis se recharge. */
        $created = 0;
        $errors = array();
        foreach ($devices as $device) {
            if (!is_array($device) || !isset($device['ip'])) {
                continue;
            }
            try {
                /* On ne croit pas le navigateur sur parole : l'appareil est
                 * réinterrogé avant d'être créé. */
                wledbe::createFromProbe(wledbe::probe($device['ip']));
                $created++;
            } catch (Throwable $e) {
                $errors[] = $device['ip'] . ' : ' . $e->getMessage();
            }
        }
        ajax::success(array('created' => $created, 'errors' => $errors));
    }

    if (init('action') == 'refresh') {
        unautorizedInDemo();
        $eqLogic = wledbeEq();
        if ($eqLogic->isGroup()) {
            $eqLogic->refreshGroup();
        } else {
            $eqLogic->device()->pollNow();
        }
        ajax::success($eqLogic->toAjax());
    }

    if (init('action') == 'lists') {
        unautorizedInDemo();
        $eqLogic = wledbeEq();
        $counts = $eqLogic->device()->refreshLists();
        $result = $eqLogic->toAjax();
        $result['counts'] = $counts;
        ajax::success($result);
    }

    /* La bibliothèque de scènes, et de quoi l'éditer : appareils, et noms
     * d'effets et de palettes connus sur l'ensemble des WLED. */
    if (init('action') == 'scenes') {
        $devices = array();
        $effects = array();
        $palettes = array();
        foreach (wledbe::byType('wledbe', true) as $eqLogic) {
            if ($eqLogic->isGroup() || $eqLogic->isSegment()) {
                continue;
            }
            $devices[] = array('id' => $eqLogic->getId(), 'name' => $eqLogic->getHumanName(), 'matrix' => $eqLogic->isMatrix());
            foreach ((array) $eqLogic->getCache('fx_names', array()) as $name) {
                if ($name !== 'RSVD' && $name !== '-') {
                    $effects[$name] = 1;
                }
            }
            foreach ((array) $eqLogic->getCache('pal_names', array()) as $name) {
                $palettes[$name] = 1;
            }
        }
        $effects = array_keys($effects);
        $palettes = array_keys($palettes);
        natcasesort($effects);
        natcasesort($palettes);
        ajax::success(array('scenes' => wledbe::scenes(), 'devices' => $devices,
            'effects' => array_values($effects), 'palettes' => array_values($palettes)));
    }

    if (init('action') == 'saveScenes') {
        unautorizedInDemo();
        ajax::success(wledbe::saveScenes(json_decode(init('scenes'), true)));
    }

    if (init('action') == 'resetScenes') {
        unautorizedInDemo();
        ajax::success(wledbe::saveScenes(wledbe::DEFAULT_SCENES));
    }

    /* Joue une scène telle qu'elle est à l'écran, sans l'enregistrer, le
     * temps d'un essai. */
    if (init('action') == 'testScene') {
        unautorizedInDemo();
        $eqLogic = wledbeEq();
        $scene = json_decode(init('scene'), true);
        if (!is_array($scene)) {
            throw new Exception(__('Scène illisible.', __FILE__));
        }
        $duration = is_numeric(init('duration')) ? max(1, min(600, (int) init('duration'))) : 10;
        ajax::success($eqLogic->playScene($scene, array('duration' => $duration, 'priority' => 100, 'test' => true)));
    }

    if (init('action') == 'stopScenes') {
        unautorizedInDemo();
        ajax::success(wledbeEq()->stopScenes(true));
    }

    /* Un équipement par segment du WLED qui n'en a pas encore. */
    if (init('action') == 'createSegments') {
        unautorizedInDemo();
        ajax::success(wledbeEq()->createSegments());
    }

    if (init('action') == 'createGroup') {
        unautorizedInDemo();
        ajax::success(array('id' => wledbe::createGroup(init('name'))->getId()));
    }

    /* Lit le cache, n'interroge jamais l'appareil. */
    if (init('action') == 'data') {
        ajax::success(wledbeEq()->toAjax());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

/* Throwable : en PHP 8 une Error n'hérite pas d'Exception. */
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
