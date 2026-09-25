# Changelog — plugin WLED

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
