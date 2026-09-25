<?php
/* Remplaçants minimaux du coeur de Jeedom, pour rejouer la classe du plugin
 * hors d'une installation. Ils ne simulent que ce dont le décodage et la
 * vérification ont besoin : traduction, configuration, journal, messages,
 * cache, et une table des commandes en mémoire.
 *
 * Le but n'est pas de tester Jeedom, mais de rejouer des réponses réelles de
 * WLED et de vérifier que le plugin en tire les bonnes valeurs, et surtout
 * qu'il juge correctement si un ordre a été appliqué : une vérification trop
 * laxe laisse passer un ordre perdu, une vérification trop stricte relance
 * sans fin un ordre qui avait réussi. */

date_default_timezone_set('Europe/Brussels');

function __($_text, $_file = null) { return $_text; }

/* Recopie fidèle de la fonction du coeur (core/php/utils.inc.php) : cmd et
 * eqLogic la passent sur tout nom enregistré. L'avoir ici, c'est voir dans les
 * tests ce que la base contiendra vraiment. */
function cleanComponanteName($_name) {
    $return = strip_tags(str_replace(array('&', '#', ']', '[', '%', "\\", "/", "'", '"', "*"), '', $_name));
    return preg_replace('/\s+/', ' ', $return);
}

class config {
    public static $values = array();
    public static function byKey($_key, $_plugin = 'core', $_default = '') {
        $k = $_plugin . '::' . $_key;
        return isset(self::$values[$k]) ? self::$values[$k] : $_default;
    }
    public static function save($_key, $_value, $_plugin = 'core') {
        self::$values[$_plugin . '::' . $_key] = $_value;
    }
}

/* Dossier temporaire du plugin : verrous de la pile et fichier témoin du
 * démon. */
class jeedom {
    public static function getTmpFolder($_plugin = null) {
        $dir = sys_get_temp_dir() . '/wledbe-tests-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        return $dir;
    }
}

class message {
    public static $added = array();
    public static function add($_type, $_message, $_action = '', $_logicalId = '', $_writeMessage = true) {
        self::$added[] = $_logicalId . ' : ' . $_message;
    }
    public static function removeAll($_plugin = '', $_logicalId = '', $_search = false) {}
}

class log {
    public static $lines = array();
    public static function add($_plugin, $_level, $_message, $_logicalId = '') {
        self::$lines[] = $_level . ' : ' . $_message;
    }
}

/* Une commande, et la table « cmd » tenue en mémoire. save() lui donne un
 * identifiant et l'inscrit ; eqLogic::getCmd() et byEqLogicIdCmdName() la
 * retrouvent comme le ferait la base. */
class cmd {
    public static $table = array();
    public static $saves = 0;

    public $id = '';
    public $eqLogic_id = '';
    public $logicalId = '';
    public $name = '';
    public $type = '';
    public $subType = '';
    public $isVisible = 0;
    public $isHistorized = 0;
    public $order = 0;
    public $unite = '';
    public $generic_type = '';
    public $value = '';
    public $configuration = array();
    public $template = array();

    public static function reset() { self::$table = array(); self::$saves = 0; }

    public function getId() { return $this->id; }
    public function getEqLogic_id() { return $this->eqLogic_id; }
    public function getLogicalId() { return $this->logicalId; }
    public function getName() { return $this->name; }
    public function getType() { return $this->type; }
    public function getSubType() { return $this->subType; }
    public function getIsVisible() { return $this->isVisible; }
    public function getIsHistorized() { return $this->isHistorized; }
    public function getOrder() { return $this->order; }
    public function getUnite() { return $this->unite; }
    public function getGeneric_type() { return $this->generic_type; }
    public function getValue() { return $this->value; }
    public function getConfiguration($_key, $_default = '') {
        return isset($this->configuration[$_key]) ? $this->configuration[$_key] : $_default;
    }
    public function getEqLogic() { return null; }

    public function setEqLogic_id($_v) { $this->eqLogic_id = $_v; return $this; }
    public function setLogicalId($_v) { $this->logicalId = $_v; return $this; }
    /* Comme cmd::setName() du coeur : le nom est nettoyé avant d'être gardé. */
    public function setName($_v) { $this->name = trim(substr(cleanComponanteName($_v), 0, 127)); return $this; }
    public function setType($_v) { $this->type = $_v; return $this; }
    public function setSubType($_v) { $this->subType = $_v; return $this; }
    public function setIsVisible($_v) { $this->isVisible = $_v; return $this; }
    public function setIsHistorized($_v) { $this->isHistorized = $_v; return $this; }
    public function setOrder($_v) { $this->order = $_v; return $this; }
    public function setUnite($_v) { $this->unite = $_v; return $this; }
    public function setGeneric_type($_v) { $this->generic_type = $_v; return $this; }
    public function setValue($_v) { $this->value = $_v; return $this; }
    public function setConfiguration($_k, $_v) { $this->configuration[$_k] = $_v; return $this; }
    public function setTemplate($_k, $_v) { $this->template[$_k] = $_v; return $this; }

    /* La contrainte d'unicité (eqLogic_id, name) de la vraie table est
     * reproduite : un doublon lève, comme DB::save() le ferait. */
    public function save() {
        foreach (self::$table as $other) {
            if ($other !== $this && $other->eqLogic_id == $this->eqLogic_id && $other->name === $this->name) {
                throw new Exception('Duplicate entry \'' . $this->eqLogic_id . '-' . $this->name . '\' for key \'unique\'');
            }
        }
        if ($this->id === '') {
            $this->id = max(array_merge(array(0), array_keys(self::$table))) + 1;
            self::$table[$this->id] = $this;
        }
        self::$saves++;
        return true;
    }

    public function remove() {
        unset(self::$table[$this->id]);
    }

    public static function byEqLogicIdCmdName($_eqLogic_id, $_name) {
        foreach (self::$table as $cmd) {
            if ($cmd->eqLogic_id == $_eqLogic_id && $cmd->name === $_name) {
                return $cmd;
            }
        }
        return null;
    }
}

class eqLogic {
    public $id = 1;
    public $configuration = array();
    public $published = array();   /* identifiant logique => dernière valeur publiée */
    public $events = 0;            /* nombre de publications */
    public $saved = 0;

    public function getId() { return $this->id; }
    public function getHumanName() { return '[Test][WLED]'; }
    public function getName() { return 'WLED'; }
    public function getIsEnable() { return 1; }
    public function getConfiguration($_key, $_default = '') {
        return array_key_exists($_key, $this->configuration) ? $this->configuration[$_key] : $_default;
    }
    public function setConfiguration($_key, $_value) {
        $this->configuration[$_key] = $_value;
        return $this;
    }

    /* Une commande n'existe que si elle a été enregistrée dans la table du
     * stub : c'est ce qui permet de vérifier que publishCmd() ignore sans
     * broncher une commande absente. */
    public function getCmd($_type = null, $_logicalId = null) {
        if ($_logicalId === null) {
            $list = array();
            foreach (cmd::$table as $cmd) {
                if ($cmd->eqLogic_id == $this->id && ($_type === null || $cmd->type === $_type)) {
                    $list[] = $cmd;
                }
            }
            return $list;
        }
        foreach (cmd::$table as $cmd) {
            if ($cmd->eqLogic_id == $this->id && $cmd->type === $_type && $cmd->logicalId === $_logicalId) {
                return $cmd;
            }
        }
        return null;
    }

    public function checkAndUpdateCmd($_cmd, $_value, $_when = null) {
        $id = is_object($_cmd) ? $_cmd->getLogicalId() : $_cmd;
        $this->published[$id] = $_value;
        $this->events++;
    }

    /* getCache() et setCache() sont publiques dans eqLogic : les redéclarer en
     * privé dans le plugin serait une erreur fatale au chargement de la classe,
     * donc un Jeedom entier en HTTP 500. Le stub les fournit à l'identique. */
    public $store = array();
    public function getCache($_key = '', $_default = '') {
        return isset($this->store[$_key]) ? $this->store[$_key] : $_default;
    }
    public function setCache($_key, $_value = null) {
        $this->store[$_key] = $_value;
    }

    public function setIsEnable($_v) {}
    public function setIsVisible($_v) {}
    public function setLogicalId($_v) {}
    public function setName($_v) {}
    public function setEqType_name($_v) {}
    public function save($_direct = false) { $this->saved++; }
    public static function byType($_type, $_onlyEnable = false) { return array(); }
    public static function byLogicalId($_logicalId, $_eqType, $_multiple = false) { return null; }
}
