# Plugin WLED

Ce plugin pilote en réseau local les contrôleurs [WLED](https://kno.wled.ge),
qu'ils animent une bande LED ou une matrice. Il parle directement à l'API JSON
de chaque appareil : ni cloud, ni compte, ni broker MQTT.

Il vise un défaut de WLED : un ordre envoyé à un contrôleur au Wi-Fi fragile
peut se perdre sans que rien ne le signale. Le plugin **vérifie donc chaque
ordre** et le relance si besoin.

## Ajouter ses WLED

Plugins → Objets connectés → WLED.

- **Rechercher des WLED** : le plugin écoute les annonces mDNS des WLED et
  interroge en parallèle toutes les adresses du sous-réseau de Jeedom. Laissez
  le sous-réseau vide pour celui de Jeedom, ou saisissez-en un autre
  (`192.168.20.0/24`) si vos WLED vivent sur un autre VLAN.
- **Ajouter par adresse IP** : pour un appareil qu'aucune recherche ne trouve.

Cochez les appareils à créer. Un appareil déjà connu n'est pas dédoublé : s'il a
changé d'adresse, l'équipement existant est simplement mis à jour.

Les équipements sont reconnus par leur **adresse MAC**. Réserver l'IP dans le
routeur reste conseillé, mais une adresse qui change est retrouvée toute seule :
une fois par heure, et dès qu'un appareil ne répond plus, le plugin écoute les
annonces mDNS et corrige l'IP.

## La page de l'équipement

- **Adresse IP** : remplie par la découverte.
- **Vérifier les ordres** : activé par défaut (voir plus bas).
- **Identité** : nom dans WLED, type (bande ou matrice, avec sa taille), nombre
  de LED, adresse MAC, version.
- **Relever maintenant**, **Relire effets et presets**, **Interface WLED**.

L'onglet **Diagnostic** montre la dernière réponse brute de l'appareil.

## Les commandes

| Commande | Type | Rôle |
|---|---|---|
| Etat | info binaire | allumé ou éteint |
| Luminosité | info, % | luminosité générale |
| Couleur | info | couleur principale du segment principal, `#rrggbb` |
| Effet / Numéro d'effet | info | effet en cours, par son nom et son numéro |
| Palette / Numéro de palette | info | palette en cours |
| Vitesse, Intensité | info, 0-255 | réglages de l'effet |
| Preset actif | info | numéro du preset, -1 si aucun |
| Pilotage externe | info binaire | WLED reçoit un flux temps réel (E1.31, DDP, Hyperion…) qui prend le pas sur Jeedom |
| En ligne | info binaire | l'appareil a répondu au dernier appel |
| Signal Wi-Fi | info, % | qualité du Wi-Fi vue par WLED |
| Vérification | info binaire | 1 si le dernier ordre a été appliqué, 0 sinon |
| Dernière vérification | info | heure et détail du dernier contrôle |
| Allumer, Éteindre, Basculer | action | |
| Régler la luminosité | action, curseur 0-100 | 0 éteint |
| Régler la couleur | action, couleur | allume et change la couleur principale |
| Choisir un effet | action, liste | allume et lance l'effet |
| Choisir une palette | action, liste | |
| Régler la vitesse, Régler l'intensité | action, curseur 0-255 | |
| Appliquer un preset | action, liste | les presets enregistrés dans WLED |
| Envoyer un état JSON | action, message | voir plus bas |
| Rafraîchir | action | relit l'état |

Couleurs, effets et réglages s'appliquent aux **segments sélectionnés** dans
WLED (ou au segment principal si aucun ne l'est), comme dans l'interface de
WLED.

### Choisir un effet depuis un scénario

La liste propose les effets de l'appareil, triés par nom. Les emplacements
réservés sont écartés, ainsi que les effets purement 2D sur une bande, où ils
n'affichent rien. Une note ♪ signale un effet sonore, qui ne réagit qu'avec un
micro.

Dans un scénario, l'effet peut être désigné par son **nom** plutôt que par son
numéro : `Chase 2` reste `Chase 2` après une mise à jour de WLED, alors que son
numéro peut changer.

### Envoyer un état JSON

Pour tout ce que les autres commandes ne couvrent pas, le message est un objet
JSON au format de l'API de WLED (`/json/state`), par exemple :

```json
{"on":true,"bri":255,"seg":{"fx":37,"col":[[255,0,0],[0,0,255]],"sx":200}}
```

Il est vérifié comme tout autre ordre. Les valeurs relatives (`"bri":"~10"`,
`"on":"t"`) sont acceptées, mais ne peuvent pas être vérifiées ; un tel ordre
n'est jamais renvoyé, pour ne pas appliquer deux fois le même « +10 ».

## La vérification des ordres

Chaque ordre est envoyé avec la demande de renvoyer l'état appliqué. Le plugin
compare cet état à ce qui a été demandé :

1. conforme : c'est terminé, en un seul échange ;
2. écart : il relit l'état après un court délai, car WLED applique certains
   ordres au tour suivant (un preset, par exemple) ;
3. écart persistant ou appareil muet : il renvoie l'ordre, en attendant un peu
   plus à chaque fois.

Après le nombre d'essais réglé dans la configuration du plugin (3 par défaut),
l'ordre est déclaré perdu : la commande **Vérification** passe à 0, **Dernière
vérification** dit ce qui ne correspond pas, un message est déposé dans le
centre de messages et la commande échoue, ce que le scénario peut constater.

Exemple : prévenir quand la lampe d'alarme n'a pas réagi.

```
SI #[Salon][Meuble][Vérification]# == 0
ALORS envoyer une notification « La lampe d'alarme ne répond pas »
```

## Configuration du plugin

- **Délai d'attente des requêtes** : 3 secondes par défaut.
- **Essais par ordre** : 3 par défaut.
- **Surveiller le réseau** : écoute mDNS horaire, qui corrige les adresses et
  signale les nouveaux WLED.
- **Créer les nouveaux WLED automatiquement** : crée l'équipement au lieu de le
  signaler.

## Relevé de l'état

L'état de chaque WLED est relu **une fois par minute**, et immédiatement après
chaque ordre. Un appareil injoignable n'est plus relu que toutes les cinq
minutes, pour ne pas retarder les autres.

## Dépannage

- **Rien n'est trouvé** : vérifiez que l'appareil répond sur
  `http://<ip>/json/info` depuis un navigateur. Sur un autre VLAN, saisissez son
  sous-réseau dans la recherche.
- **Des ordres échouent régulièrement** : regardez la commande Signal Wi-Fi.
  Sous 30 %, les pertes sont fréquentes ; un répéteur est plus efficace qu'un
  nombre d'essais plus élevé.
- **Pilotage externe à 1** : un flux temps réel commande les LED ; les ordres de
  Jeedom sont acceptés mais invisibles tant qu'il dure.
