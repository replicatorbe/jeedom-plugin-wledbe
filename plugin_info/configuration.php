<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-check-double"></i> {{Envoi des ordres}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai d'attente des requêtes}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="api_timeout" placeholder="3" min="1" max="15">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Secondes avant d'abandonner un appel à un WLED. Il répond d'habitude en une fraction de seconde ; trois secondes laissent de la marge à un Wi-Fi faible.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Essais par ordre}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="tries" placeholder="3" min="1" max="6">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Nombre d'envois d'un ordre avant de le déclarer perdu, quand WLED ne répond pas ou que l'état relu ne correspond pas. L'attente double entre deux essais.}}</span>
			</div>
		</div>

		<legend><i class="fas fa-search"></i> {{Découverte}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Surveiller le réseau}}</label>
			<div class="col-md-1">
				<input type="checkbox" class="configKey" data-l1key="auto_discover">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{Une fois par heure, le plugin écoute les annonces mDNS des WLED : il corrige l'adresse IP des appareils connus qui en ont changé et signale les nouveaux dans le centre de messages. Même décochée, cette écoute a lieu quand un appareil connu ne répond plus.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Créer les nouveaux WLED automatiquement}}</label>
			<div class="col-md-1">
				<input type="checkbox" class="configKey" data-l1key="auto_create">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{Au lieu de les signaler, crée directement un équipement pour chaque WLED inconnu entendu sur le réseau.}}</span>
			</div>
		</div>
	</fieldset>
</form>
