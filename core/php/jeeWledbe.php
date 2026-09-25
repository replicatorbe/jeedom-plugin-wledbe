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
 * Point d'entrée du démon, protégé par la clé API du plugin :
 *   GET ?apikey=…&action=schedule     → les prochains réveils, en JSON
 *   GET ?apikey=…&action=tick&eq=ID   → fait avancer les scènes de l'appareil
 *
 * Le démon ne sait rien des scènes : il rappelle à l'heure dite, et tick()
 * décide de ce qu'il y a à faire.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

if (!jeedom::apiAccess(init('apikey'), 'wledbe')) {
    http_response_code(401);
    echo 'Not authorized';
    die();
}

if (init('action') == 'schedule') {
    /* Preuve de vie : un démon qui ne joint plus Jeedom est déclaré arrêté
     * (wledbe::deamon_info), puis relancé. */
    cache::set('wledbe::daemon_seen', time());
    header('Content-Type: application/json');
    echo json_encode(wledbe::getSchedule());
    die();
}

if (init('action') == 'tick') {
    $eqLogic = eqLogic::byId((int) init('eq'));
    /* Appareil supprimé ou désactivé depuis le dernier planning. */
    if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'wledbe' || !$eqLogic->getIsEnable()) {
        echo 'OK';
        die();
    }
    try {
        $eqLogic->tick();
    } catch (Throwable $e) {
        log::add('wledbe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
    }
    echo 'OK';
    die();
}

http_response_code(400);
echo 'Unknown action';
