# Plugin Jeedom — WLED

Pilote en réseau local les contrôleurs **WLED**, bandes LED et matrices, sans
cloud ni broker MQTT.

- **Découverte automatique** : mDNS (`_wled._tcp`), puis balayage HTTP du
  sous-réseau pour les réseaux où le multicast ne passe pas. Les appareils sont
  reconnus par leur adresse MAC : une IP qui change est corrigée sans recréer
  l'équipement.
- **Commandes d'une lumière Jeedom** : allumer, éteindre, basculer, luminosité,
  couleur, effet, palette, vitesse, intensité, preset, et un état JSON libre
  pour les scénarios.
- **Ordres vérifiés** : le plugin relit l'état que WLED a réellement appliqué,
  relance un ordre perdu, et signale un échec définitif (commande
  « Vérification », centre de messages).
- **Scènes** : alarme, incendie, police, fuite d'eau, sonnette, notification…
  Un effet joué pendant une durée, avec une priorité, puis l'éclairage d'avant
  rendu tel quel. Recettes distinctes pour bande et matrice (texte défilant),
  pile de priorités, garde qui réimpose une alarme défaite. Un petit démon PHP
  les réveille à la seconde près.
- **Listes lues sur l'appareil** : les numéros d'effets changent d'une version
  de WLED à l'autre ; le plugin relit les listes à chaque changement de version
  et écarte les effets 2D sur une bande.

Aucune dépendance à installer. `avahi-browse` (paquet `avahi-utils`) est
utilisé s'il est présent ; sinon, seul le balayage HTTP sert à la découverte.

## Installation

Plugins → Gestion des plugins → Ajouter → Github, puis
`replicatorbe / jeedom-plugin-wledbe`, branche `master` pour le canal stable ou
`beta` pour la version de développement.

Ensuite : Plugins → Objets connectés → WLED → **Rechercher des WLED**.

La documentation complète est dans [`docs/fr_FR/index.md`](docs/fr_FR/index.md).

## Développement

```bash
php tests/run.php            # rejeu hors ligne sur des réponses réelles de WLED
php tests/check-classes.php  # pièges du coeur, par réflexion
```

## Licence

AGPL v3. Ce plugin n'est pas affilié au projet WLED.
