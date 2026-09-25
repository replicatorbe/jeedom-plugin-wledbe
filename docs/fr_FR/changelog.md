# Changelog — plugin WLED

## 0.3 (beta)

- Groupes : un équipement qui pilote plusieurs WLED d'un seul ordre, envoyé
  à tous en parallèle puis vérifié membre par membre ; effets et palettes
  communs proposés par leur nom ; scènes et texte transmis à chaque membre.
- Texte sur matrice : commande « Afficher un texte », avec couleur (nommée
  ou #rrggbb), durée, vitesse, priorité ; jetons d'heure et de date de WLED.
- État instantané : connexion WebSocket du démon à chaque WLED ; l'état suit
  en une à deux secondes et la garde réagit aussitôt. Réglable dans la
  configuration.

## 0.2.1 (beta)

Relecture complète du code, corrections :

- Sécurité : un WLED (ou un faux WLED) du réseau ne peut plus injecter de
  balisage dans la page par son nom ou sa version ; la clé API du démon
  n'apparaît plus dans la liste des processus.
- Identité : un autre WLED qui répond à l'adresse d'un équipement n'en prend
  plus l'identité ; la MAC d'un équipement créé à la main est apprise au
  premier relevé ; la découverte vérifie l'appareil avant de déplacer un
  équipement.
- Scènes : restauration fiable après un appareil muet, même si une autre
  scène arrive entre-temps ; plus de réveils en boucle ; une scène injouable
  est retirée proprement ; une scène supprimée quitte les piles ; une commande
  manuelle garde les scènes programmées ; texte défilant limité à 32 octets.
- Démon : plus de journal inondé ni de boucle si Jeedom refuse l'accès, arrêt
  et relance automatique ; réveils en parallèle ; prise en compte de deux
  changements dans la même seconde.
- Éditeur de scènes : « Essayer » n'enregistre plus rien, les saisies ne sont
  plus perdues en repliant le panneau, un champ vidé garde sa valeur par
  défaut, Jeedom prévient avant de quitter avec des modifications.
- Relevé des appareils en parallèle ; découverte limitée aux vraies
  interfaces réseau ; sous-réseau validé.

## 0.2 (beta)

- Scènes : bibliothèque commune à tous les WLED, livrée avec alarme,
  incendie, police, fuite d'eau, sonnette et notification, éditable depuis la
  page du plugin, avec essai sur un appareil.
- Recettes distinctes pour bande et matrice, texte défilant sur matrice.
- Durée, délai, heure de départ, priorité et comportement de fin, réglables
  par scène et à chaque lancement.
- Pile de priorités par appareil, restauration de l'éclairage d'avant la
  première scène, playlist comprise.
- Garde : une scène protégée défaite est réimposée.
- Démon de minuterie ; le cron prend le relais s'il est arrêté.

## 0.1 (beta)

- Découverte des WLED par mDNS et par balayage HTTP du sous-réseau.
- Reconnaissance par adresse MAC, correction automatique de l'adresse IP.
- Détection bande ou matrice.
- Commandes : allumer, éteindre, basculer, luminosité, couleur, effet, palette,
  vitesse, intensité, preset, état JSON libre, rafraîchir.
- Vérification de chaque ordre, avec relance et signalement des échecs.
- Relevé de l'état chaque minute.
