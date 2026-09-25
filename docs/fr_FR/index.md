# Plugin WLED

Ce plugin pilote en réseau local les contrôleurs [WLED](https://kno.wled.ge),
qu'ils animent une bande LED ou une matrice. Il parle directement à l'API JSON
de chaque appareil : ni cloud, ni compte, ni broker MQTT.

Il comble deux manques de WLED :

- un ordre envoyé à un contrôleur au Wi-Fi fragile peut se perdre sans que rien
  ne le signale : le plugin **vérifie chaque ordre** et le relance si besoin ;
- WLED sait jouer un effet, pas « flasher en rouge pendant cinq minutes puis
  revenir comme avant » : le plugin ajoute des **scènes** (alarme, police,
  sonnette…) avec durée, priorité et restauration.

## Ajouter ses WLED

Plugins → Objets connectés → WLED.

- **Rechercher des WLED** : le plugin écoute les annonces mDNS des WLED et
  interroge en parallèle toutes les adresses du sous-réseau de Jeedom. Laissez
  le sous-réseau vide pour celui de Jeedom, ou saisissez-en un autre
  (`192.168.20.0/24`) si vos WLED vivent sur un autre VLAN.
- **Ajouter par adresse IP** : pour un appareil qu'aucune recherche ne trouve.

Cochez les appareils à créer. Un appareil déjà connu n'est pas dédoublé : s'il a
changé d'adresse, l'équipement existant est simplement mis à jour.

Les équipements sont reconnus par leur **adresse MAC**. Si un autre WLED répond
un jour à l'adresse d'un équipement (IP réattribuée par le routeur), le plugin
le voit et n'en tient pas compte : l'équipement passe hors ligne au lieu de
piloter le mauvais appareil.

Réserver l'IP dans le routeur reste conseillé. À défaut, une adresse qui change
est retrouvée par l'écoute mDNS horaire (si « Surveiller le réseau » est coché,
et de toute façon pour un appareil qui ne répond plus depuis trois relevés).
Cette écoute ne traverse ni Docker ni les VLAN : dans ces installations,
relancez « Rechercher des WLED » après un changement d'adresse.

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

## Les scènes

Une scène, c'est ce que WLED ne sait pas faire seul : **un effet choisi pour une
situation**, joué pendant une durée donnée, avec une priorité, puis
**l'éclairage d'avant rendu tel quel**. Une alarme fait flasher la bande, puis
la lampe revient exactement comme elle était, allumée ou éteinte, avec sa
couleur et son effet.

### La bibliothèque

Plugins → Objets connectés → WLED → **Scènes**. La bibliothèque est commune à
tous vos WLED. Elle est livrée avec six scènes, toutes modifiables :

| Scène | Bande | Matrice | Priorité | Durée | Garde |
|---|---|---|---|---|---|
| Alarme intrusion | Strobe Mega rouge et blanc | texte « ALARME » | 100 | 5 min | oui |
| Incendie | Strobe orange | texte « FEU » | 100 | 5 min | oui |
| Police | Chase 2 rouge et bleu | recette bande | 90 | 2 min | non |
| Fuite d'eau | Running bleu | texte « FUITE » | 80 | 5 min | oui |
| Sonnette | Blink blanc | recette bande | 40 | 10 s | non |
| Notification | Breathe bleu clair | recette bande | 20 | 15 s | non |

Pour chaque scène :

- **Priorité** (0 à 100) : une scène plus prioritaire recouvre les autres.
- **Durée** en secondes ; 0 pour une scène sans fin, qui dure jusqu'à
  « Arrêter la scène en cours » ou « Arrêter toutes les scènes ».
- **Ensuite** : rendre l'éclairage d'avant, éteindre, ou laisser la scène.
- **Sous garde** : la scène est relue toutes les dix secondes et réimposée si
  quelqu'un l'a défaite (bouton de l'appareil, redémarrage, autre système).
- **Recettes** : une pour les bandes et, facultativement, une pour les
  matrices. Effet et palette se choisissent par leur nom, parmi ceux de vos
  WLED ; trois couleurs, luminosité, vitesse, intensité. Sur une matrice, le
  **texte défilant** s'affiche avec l'effet « Scrolling Text » ; WLED le limite
  à 32 caractères (moins avec des accents). Le **JSON
  avancé** complète l'ordre pour tout le reste, par exemple
  `{"seg":{"c1":200}}` ou `{"lor":1}` pour passer outre un flux temps réel.

**Essayer** joue la scène telle qu'elle est à l'écran, sur le WLED choisi et
pendant la durée indiquée, **sans rien enregistrer**. **Arrêter les scènes de
cet appareil** arrête toutes ses scènes, y compris une vraie alarme en cours,
et rend l'éclairage.

Rien n'est enregistré avant **Enregistrer les scènes** ; Jeedom prévient si
l'on quitte la page avec des modifications en attente. Supprimer une scène
supprime, à l'enregistrement, sa commande « Scène … » sur tous les équipements :
un scénario qui l'appelait est à revoir. Une bibliothèque entièrement vidée
reste vide ; **Scènes d'origine** remet celles livrées avec le plugin.

Une commande « Scène … » renommée à la main garde son nom, même si la scène
est renommée ensuite.

Les effets sont désignés par leur nom et retrouvés sur chaque appareil au
moment de jouer : la même scène marche sur un WLED 0.14 et sur un 16.0. Si
l'effet n'existe pas sur un appareil, la commande échoue avec un message qui
le dit.

### Lancer une scène

Chaque équipement reçoit :

| Commande | Rôle |
|---|---|
| Scène Police, Scène Sonnette… | une par scène, avec ses réglages par défaut |
| Lancer une scène | nom de la scène en **titre**, options en **message** |
| Arrêter la scène en cours | la suivante reprend la main, ou l'éclairage revient |
| Arrêter toutes les scènes | vide la pile et rend l'éclairage d'avant |
| Scène en cours, Scène active, Fin de la scène | infos |

Les options de « Lancer une scène », séparées par des espaces :

| Option | Exemple | Effet |
|---|---|---|
| durée | `durée=30`, `durée=5m`, `durée=1h`, `durée=0`, ou `30` seul | remplace la durée par défaut ; 0 = sans fin |
| délai | `délai=10`, `délai=2m` | attend avant de lancer |
| heure | `heure=22:30` | lance à cette heure (demain si elle est passée) |
| priorité | `priorité=95` | remplace la priorité |
| fin | `fin=restaurer`, `fin=éteindre`, `fin=garder` | remplace le comportement de fin |

Exemple, dans le scénario d'alarme :

```
[Salon][Meuble][Lancer une scène]   titre : Alarme intrusion   message : durée=10m
```

### Plusieurs scènes à la fois

Chaque appareil tient une **pile** : la scène affichée est la plus prioritaire,
la plus récente à priorité égale. Si la sonnette sonne pendant l'alarme, elle
attend dessous ; si l'alarme arrive pendant la sonnette, elle prend la main, et
la sonnette reprend si elle n'est pas finie. Quand la dernière scène se
termine, l'éclairage d'avant la **première** revient.

Relancer une scène déjà en cours la prolonge : sa durée repart de zéro.

Une **commande manuelle** (allumer, éteindre, couleur, effet…) pendant une
scène abandonne les scènes en cours sans rien restaurer : l'utilisateur a repris
la main, éteindre la lampe pendant la sonnette doit la laisser éteinte. Les
scènes programmées pour plus tard restent prévues.

Une scène déjà lancée n'est pas modifiée si l'on change la bibliothèque : elle
finit avec les réglages qu'elle avait au départ. Une scène supprimée de la
bibliothèque est en revanche retirée des piles.

Si une scène ne peut pas être jouée sur un appareil (effet absent de sa version
de WLED, par exemple), elle est retirée de sa pile, la scène suivante reprend,
et un message le signale.

L'onglet **Diagnostic** de l'équipement montre la pile : scène affichée,
scènes recouvertes, scènes programmées.

### Le démon

Le démon du plugin réveille les scènes à la seconde près : fin de durée,
départ différé, garde. Il ne parle jamais aux WLED lui-même, et réveille les
appareils en parallèle : un WLED muet ne retarde pas les autres. S'il est
arrêté, ou s'il ne joint plus Jeedom (clé API régénérée, accès API du plugin
restreint), le cron de Jeedom prend le relais, une fois par minute, et la
gestion automatique des démons le relance : une scène ne reste jamais allumée
faute de démon, elle s'arrête simplement moins précisément.

Si un WLED ne répond pas au moment de rendre l'éclairage, la restauration est
retentée toutes les 30 secondes pendant une demi-heure, puis abandonnée avec
un message : une restauration très tardive écraserait ce qui a été fait depuis.
Une commande manuelle l'annule aussi.

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
