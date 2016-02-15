<?php

/**
 * Objet générique de gestion de la configuration, offrant la possibilité d'avoir plusieurs niveaux de paramétrage, avec héritage entre les niveaux et surcharge si nécessaire. Cet objet a vocation à être utilisé en définissant un objet qui en hérite, et en redéfinissant certaines fonctions.
 * Dans le modèle de données, cet objet représente une série de paramètre de configuration, pour un seul type de configuration (générale OU utilisateur...). La configuration dans un contexte donné pour un utilisateur correspond donc à un croisement (tenant compte des surcharges) de plusieurs objets PluginConfigmanagerConfig de différents types (mais au plus un seul de chaque type).
 * Chaque objet PluginConfigmanagerConfig est instancié à la volée quand on essaie d'y accéder en écriture. L'absence de l'objet est considérée comme équivalente à un héritage de l'objet du niveau de dessus, ou à la valeur par défaut s'il n'y a pas de niveau de dessus.
 * 
 * @author Etiennef
 */


/*
 * $params, les clés de $values, $size, $maxlength doivent êter htmlentities
 */


class PluginConfigmanagerRule extends PluginConfigmanagerCommon {
	const NEW_ID_TAG = '__newid__';
	const NEW_ORDER_TAG = '__neworder__';
	
	/**
	 * Description de l'ordre dans lequel l'héritage des règles se déroule
	 * Dans l'ordre, le premier hérite du second, etc...
	 */
	protected static $inherit_order = array();
	
	/**
	 * Création des tables liées à cet objet. Utilisée lors de l'installation du plugin
	 */
	public final static function install() {
		global $DB;
		$table = self::getTable();
		$request = '';
		
		$query = "CREATE TABLE `$table` (
					`" . self::getIndexName() . "` int(11) NOT NULL AUTO_INCREMENT,
					`config__type` varchar(50) collate utf8_unicode_ci NOT NULL,
					`config__type_id` int(11) collate utf8_unicode_ci NOT NULL,
					`config__order` int(11) collate utf8_unicode_ci NOT NULL,";
		
		foreach(self::getConfigParams() as $param => $desc) {
			$query .= "`$param` " . $desc['dbtype'] . " collate utf8_unicode_ci,";
		}
		
		$query .= "PRIMARY KEY  (`" . self::getIndexName() . "`)
				) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
		
		if(! TableExists($table)) {
			$DB->queryOrDie($query, $DB->error());
		}
	}

	/**
	 * Suppression des tables liées à cet objet. Utilisé lors de la désinstallation du plugin
	 * @return boolean
	 */
	public final static function uninstall() {
		global $DB;
		$table = self::getTable();
		
		if(TableExists($table)) {
			$query = "DROP TABLE `$table`";
			$DB->queryOrDie($query, $DB->error());
		}
		return true;
	}
	
	/**
	 * Regarde dans la description s'il existe des éléments de configuration pour ce type
	 * @param string $type type de configuration
	 * @return boolean
	 */
	protected final static function hasFieldsForType($type) {
		return in_array($type, static::$inherit_order);
	} // Note: la fonction n'est pas utilisée dans cette classe, mais elle est appellée depuis common.class
	

	
	/**
	 * Lit un jeu de règle pour un item de configuration donné, sans tenir compte de l'héritage.
	 * Fonctionne avec un jeu de singletons pour éviter les appels à la base inutiles.
	 * @param string $type type de configuration
	 * @param integer $type_id type_id de l'item à lire
	 * @return array tableau représentant le jeu de règle (brut de BDD)
	 */
	private final static function getFromDBStaticNoInherit($type, $type_id) {
		if(! isset(self::$_rules_instances[$type][$type_id])) {
			if(!isset(self::$_rules_instances[$type])) self::$_rules_instances[$type] = array();
			self::$_rules_instances[$type][$type_id] = (new static())->find("`config__type`='$type' AND `config__type_id`='$type_id'", "config__order");
		}
		return self::$_rules_instances[$type][$type_id];
	}
	private static $_rules_instances = array();
	
	
	/**
	 * Lit un jeu de règle pour un item de configuration donné, en tenant compte de l'héritage (mais seulement à partir du type donné en argument).
	 * Fonctionne avec un jeu de singletons pour éviter les appels à la base inutiles.
	 * @param string $type type de configuration
	 * @param integer $type_id type_id de l'item à lire
	 * @param array(string) $values valeurs de type_id à utiliser pour lire les règles héritées (devinées si non précisées)
	 * @return array tableau représentant le jeu de règle (brut de BDD)
	 */
	private final static function getFromDBStatic($type, $type_id, $values=array()) {
		$pos = array_search($type, static::$inherit_order);
		
		//Lecture des règles de cet item de configuration
		$rules = self::getFromDBStaticNoInherit($type, $type_id);
		
		// Réccupère les règles du niveau de dessus si pertinent
		if(isset(static::$inherit_order[$pos + 1])) {
			$type2 = static::$inherit_order[$pos + 1];
			$type_id2 = self::getTypeIdForCurrentConfig($type2);
			$inherited_rules = self::getFromDBStatic($type2, $type_id2, $values);
		} else {
			return $rules;
		}
		
		//Fusion des règles de cet item avec les règles héritées
		$result = array(); $beforezero = true;
		foreach($rules as $id => $rule) {
			if($rule['config__order']>0 && $beforezero) {
				$beforezero = false;
				$result = array_merge($result, $inherited_rules);
			}
			$result[$id] = $rule;
		}
		if($beforezero) $result = array_merge($result, $inherited_rules);
		
		return $result;
	}
	
	/**
	 * Lit le jeu de règles à appliquer, en tenant compte de l'héritage.
	 * Fonctionne avec un jeu de singletons pour éviter les appels à la base inutiles.
	 * @param array(string) $values valeurs de type_id à utiliser pour lire les règles héritées (devinées si non précisées)
	 * @return array tableau représentant le jeu de règle (brut de BDD)
	 */
	public final static function getRulesValues($values=array()) {
		$type = self::$inherit_order[0];
		$type_id = self::getTypeIdForCurrentConfig($type, $values);
		return self::getFromDBStatic($type, $type_id, $values);
	}
	
	

	/**
	 * Vérifie que l'utilisateur a les droits de faire l'ensemble d'action décrits dans $input
	 * Agit comme une série de CommonDBTM::check en faisant varier l'objet sur lequel elle s'applique et le droit demandé
	 * @param array $input tableau d'actions (typiquement POST après le formulaire)
	 */
	public final static function checkAll($input) {
		$instance = new static();
	
		if(isset($input['rules'])) {
			foreach($input['rules'] as $id=>$rule) {
				if(preg_match('/'.self::NEW_ID_TAG.'(\d*)/', $id)) {
					$instance->check(-1, 'w', $rule);
				} else {
					$instance->check($id, 'w');
				}
			}
		}
	
		if(isset($input['delete_rules'])) {
			foreach($input['delete_rules'] as $id) {
				$instance->check($id, 'd');
			}
		}
	}
	
	/**
	 * Enregistre en BDD l'ensemble d'action décrits dans $input
	 * Agit comme une série de CommonDBTM::add/update/delete sur différents objets
	 * @param array $input tableau d'actions (typiquement POST après le formulaire)
	 */
	public final static function updateAll($input) {
		$instance = new static();
	
		if(isset($input['rules'])) {
			foreach($input['rules'] as $id=>$rule) {
				if(preg_match('/'.self::NEW_ID_TAG.'(\d*)/', $id)) {
					$instance->add($rule);
				} else {
					$rule[self::getIndexName()] = $id;
					$instance->update($rule);
				}
			}
		}
	
		if(isset($input['delete_rules'])) {
			foreach($input['delete_rules'] as $id) {
				$instance->delete(array(self::getIndexName() => $id));
			}
		}
	}

	/**
	 * Gère la transformation des inputs multiples en quelque chose d'inserable dans la base (en l'occurence une chaine json).
	 * .
	 * @see CommonDBTM::prepareInputForUpdate()
	 */
	final function prepareInputForUpdate($input) {
		foreach(self::getConfigParams() as $param => $desc) {
			if(isset($input[$param]) && self::isMultipleParam($param)) {
				$input[$param] = exportArrayToDB($input[$param]);
			}
		}
		return $input;
	}
	
	
	
	static protected final function showForm($type, $type_id) {
		if(! self::canItemStatic($type, $type_id, 'r')) {
			return false;
		}
		$can_write = self::canItemStatic($type, $type_id, 'w');
		
		$form_id = 'configmanager_rules_form_'.mt_rand();
		if($can_write) {
			echo '<form id="'.$form_id.'" action="' . PluginConfigmanagerRule::getFormURL() . '" method="post">';
		}
		
		$empty_rule = array(
			'id' => self::NEW_ID_TAG,
			'config__type' => $type,
			'config__type_id' => $type_id,
			'config__order' => self::NEW_ORDER_TAG
		);
		foreach(self::getConfigParams() as $param => $desc) {
			$empty_rule[$param] = $desc['default'];
		}
		
		$current_rules = self::getFromDBStatic($type, $type_id);
		
		$delete_input = '<input type="hidden" name="delete_rules[]" value="'.self::NEW_ID_TAG.'">';
				
		echo '<table class="tab_cadre_fixe">';
		
		// Ligne de titres
		echo '<tr class="headerRow">';
		foreach(self::getConfigParams() as $param => $desc) {
			echo '<th>'.$desc['text'].'</th>';
		}
		echo '<th>'.__('Actions', 'configmanager').'</th>';
		echo '</tr>';
		
		// Affichage des règles
		$table_id = 'configmanager_rules_tbody_'.mt_rand();
		echo '<tbody id="'.$table_id.'">';
		if($current_rules) {
			foreach($current_rules as $rule) {
				$can_write2 = $can_write && $rule['config__type']==$type && $rule['config__type_id']==$type_id;
				echo self::makeRuleTablerow($rule, $can_write2);
			}
		}
		echo '</tbody>';
		
		if($can_write) {
			echo '<tr>';
			echo '<td class="center"><a class="pointer" onclick="configmanager.addlast()"><img src="/pics/menu_add.png" title=""></a></td>';
			echo '<td class="center" colspan="'.(count(self::getConfigParams())).'">';
			echo '<input type="hidden" name="config__object_name" value="' . get_called_class() . '">';
			echo '<input type="submit" name="update" value="' . _sx('button', 'Save') . '" class="submit">';
			echo '</td></tr>';
		}
		echo '</table>';
		Html::closeForm();

		include GLPI_ROOT . "/plugins/configmanager/scripts/rules.js.php";
	}
	
	/**
	 * Construit le code HTML pour la ligne de tableau correspondant à une règle
	 * @param array $rule la règle à afficher
	 * @param boolean $can_write indique si la règle doit être affichée en lecture seule ou éditable
	 * @return string code html perméttant d'afficher la règle
	 */
	private static final function makeRuleTablerow($rule, $can_write) {
		$output = '';
		$output .= '<tr id="configmanager_rule_'.$rule['id'].'"'.($can_write?'':' class="tab_bg_1"').'>';
		foreach(self::getConfigParams() as $param => $desc) {
			$output .= '<td>';
			if(is_array($desc['values'])) {
				$output .= self::makeDropdown($rule['id'], $param, $desc, $rule[$param], $can_write);
			} else if($desc['values'] === 'text input') {
				$output .= self::makeTextInput($rule['id'], $param, $desc, $rule[$param], $can_write);
			}
			$output .= '</td>';
		}
		
		$output .= '<td style="vertical-align:middle">';
		if($can_write) {
			$output .= '<input type="hidden" name="rules['.$rule['id'].'][config__type]" value="'.$rule['config__type'].'">';
			$output .= '<input type="hidden" name="rules['.$rule['id'].'][config__type_id]" value="'.$rule['config__type_id'].'">';
			$output .= '<input type="hidden" name="rules['.$rule['id'].'][config__order]" value="'.$rule['config__order'].'">';
			
			// TODO ajouter des infobulles
			$output .= '<table><tr style="vertical-align:middle">';
			$output .= '<td><a class="pointer" onclick="configmanager.moveup(\''.$rule['id'].'\')"><img src="/pics/deplier_up.png" title=""></a></td>';
			$output .= '<td><a class="pointer" onclick="configmanager.movedown(\''.$rule['id'].'\')"><img src="/pics/deplier_down.png" title=""></a></td>';
			$output .= '<td><a class="pointer" onclick="configmanager.add(\''.$rule['id'].'\')"><img src="/pics/menu_add.png" title=""></a></td>';
			$output .= '<td><a class="pointer" onclick="configmanager.remove(\''.$rule['id'].'\')"><img src="/pics/reset.png" title=""></a></td>';
			$output .= '</table></tr>';
		} else {
			$output .= self::getInheritedFromMessage($rule['config__type']);
		}
		
		$output .= '</td></tr>';
		
		return $output;
	}
	
	
	
	/**
	 * Construit le code HTML pour un champ de saisie via dropdown
	 * @param integer/string $id id de la règle dont fait partie le dropdown (integer ou tag de nouvel id)
	 * @param string $param nom du paramètre à afficher (champ name du select)
	 * @param array $desc description du paramètre à afficher
	 * @param string $values valeur(s) à pré-sélectionner (sous forme de tableau json si la sélection multiple est possible)
	 * @param boolean $can_write vrai ssi on doit afficher un menu sélectionnable, sinon on affiche juste le texte.
	 * @return string code html à afficher
	 */
	private static final function makeDropdown($id, $param, $desc, $values, $can_write) {
		$result = '';
		$options = isset($desc['options']) ? $desc['options'] : array();
		$options['display'] = false;
		
		if(isset($options['multiple']) && $options['multiple']) {
			$options['values'] = importArrayFromDB($values);
		} else {
			$options['values'] = array($values);
		}
		
		if($can_write) {
			$result .= Dropdown::showFromArray("rules[$id][$param]", $desc['values'], $options);
		} else {
			foreach($options['values'] as $value) {
				if(isset($desc['values'][$value])) { //test certes contre-intuitif, mais nécessaire pour gérer le fait que la liste de choix puisse être variable selon les droits de l'utilisateur.
					$result .= $desc['values'][$value] . '</br>';
				}
			}
		}
		
		return $result;
	}
	

	/**
	 * Fonction d'affichage d'un champs de saisie texte libre
	 * @param unknown $param nom du paramètre à afficher
	 * @param unknown $desc description de la configuration de ce paramètre
	 * @param unknown $inheritText texte à afficher pour le choix 'hériter', ou '' si l'héritage est impossible pour cette option
	 * @param unknown $can_write vrai ssi on doit afficher un input éditable, sinon on affiche juste le texte.
	 */
	/**
	 * Construit le code HTML pour un champ de saisie texte libre
	 * @param integer/string $id id de la règle dont fait partie le champ (integer ou tag de nouvel id)
	 * @param string $param nom du paramètre à afficher (champ name du select)
	 * @param array $desc description du paramètre à afficher
	 * @param string $values valeur à utiliser pour préremplir le champ (doit être html-échappée)
	 * @param boolean $can_write vrai ssi on doit afficher un menu sélectionnable, sinon on affiche juste le texte.
	 * @return string code html à afficher
	 */
	private static final function makeTextInput($id, $param, $desc, $value, $can_write) {
		$result = '';
		$size = isset($desc['options']['size']) ? $desc['options']['size'] : 50;
		$maxlength = isset($desc['options']['maxlength']) ? $desc['options']['maxlength'] : 250;
		
		if($can_write) {
			$result .= '<input type="text" name="rules['.$id.']['.$param.']" value="'.$value.'" size="'.$size.'" maxlength="'.$maxlength.'">';
		} else {
			$result .= $value;
		}
		
		return $result;
	}
	
	
	
	
}
?>

























