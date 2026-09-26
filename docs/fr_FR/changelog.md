# Changelog — plugin WLED

## 0.4.3 (beta)

- Découverte : « Rechercher des WLED » lance la recherche dès le clic, sur
  le réseau de Jeedom. Elle ouvrait d'abord une fenêtre qui invitait à
  laisser le champ vide, mais Jeedom traite un champ vide comme Annuler :
  la recherche ne partait pas, sans rien afficher. Elle se déroule
  désormais dans un panneau de la page, avec un compteur, puis un bilan
  (sous-réseaux parcourus, adresses interrogées, réponses mDNS, durée). Les
  WLED trouvés s'y cochent et se créent ; si rien ne répond, le panneau dit
  quoi vérifier et propose un autre sous-réseau ou une adresse IP.

## 0.4.2 (beta)

- Texte sur matrice : option `sens=inverse`, le texte défile de gauche à
  droite. Elle coche la case « Reverse » de l'effet Scrolling Text, présente
  sur les versions récentes de WLED ; sur une version qui ne l'a pas (0.14),
  le plugin refuse avec un message clair au lieu d'afficher le texte en
  miroir. Un texte normal décoche cette case si un autre usage l'avait
  laissée cochée ; la fin du texte rend sa valeur d'avant.

## 0.4.1 (beta)

- Matrices : la commande « Afficher un texte » est visible sur le dashboard.
  Elle naissait cachée, et on ne pouvait écrire un texte que depuis un
  scénario. Les matrices existantes la voient apparaître une fois, à la
  mise à jour du plugin ; la cacher de nouveau reste possible.

## 0.4.0 (beta)

- Segments : chaque segment d'un WLED peut devenir un équipement à part, une
  lumière complète (état, luminosité, couleur, effet, palette, vitesse,
  intensité, JSON), créée par le bouton « Créer les segments » de la page du
  WLED. L'état suit en direct, par le WLED, qui reste seul relevé et
  vérifié. Allumer une zone d'un WLED éteint n'allume qu'elle ; éteindre la
  dernière zone allumée éteint le WLED. Une commande de segment abandonne
  les scènes de son WLED ; supprimer le WLED supprime ses segments.

## 0.3.4 (beta)

- Relevé : un WLED en connexion directe (État instantané) n'est plus relu
  chaque minute, mais toutes les cinq minutes ; il signale déjà lui-même
  chaque changement. Moins de requêtes pour les ESP8266.
- Relecture horaire : seuls les presets sont relus (une requête au lieu de
  quatre par appareil) ; effets et palettes le sont toujours à chaque
  changement de version.
- Page Santé de Jeedom : WLED joignables, derniers ordres non appliqués,
  connexions directes, restaurations en attente.
- Nouvelles commandes info : Consommation estimée (mA), Durée de
  fonctionnement (détecte un redémarrage), Version WLED.
- Éditeur de scènes : l'aide du texte défilant ne parle plus des accents
  comme d'une limite, ils sont retirés depuis la 0.3.1.

## 0.3.3 (beta)

- Découverte : les WLED cochés dans la fenêtre « WLED trouvés » sont de
  nouveau créés. La fenêtre de Jeedom 4.4 est retirée avant la validation,
  les cases n'étaient plus lisibles et rien n'était créé, sans message. Les
  choix sont désormais retenus à chaque clic ; valider sans rien cocher
  affiche un avertissement.

## 0.3.2 (beta)

- Scène « Police » : effet « Two Dots » (l'ancien effet Police de WLED),
  deux faisceaux bleus qui tournent sur fond noir, comme un gyrophare belge.
  « Chase 2 » mélangeait le rouge et le bleu en mauve.
- Nouvelle scène « Police bleu rouge » : l'ancien « Police All » de WLED,
  la bande entière moitié bleue, moitié rouge, qui tourne.
  Une bibliothèque déjà enregistrée garde sa recette : choisissez « Two Dots »
  dans l'éditeur, ou « Scènes d'origine ».

## 0.3.1 (beta)

Relecture de la 0.3, corrections :

- Groupes : enregistrer un groupe sans toucher à ses cases n'efface plus ses
  membres ; un membre hors ligne ne fait plus attendre le scénario ; un ordre
  relatif n'est jamais appliqué deux fois ; listes d'effets du groupe fidèles
  à celles des membres (pas d'effet 2D pour une bande) ; état et « Membres en
  ligne » à jour quand un membre tombe, revient, est désactivé ou supprimé ;
  membres ignorés affichés avec leur raison.
- Texte : titre et message dans le même ordre que « Lancer une scène » (le
  texte en titre) ; accents retirés, que WLED 0.14 n'affiche pas ; mots-clés
  d'heure et de date documentés tels que la 0.14 les connaît ; un texte en
  cours ou programmé survit à l'enregistrement des scènes.
- Démon : connexions directes gardées pendant un long réveil ; reconnexions
  espacées quand un appareil accepte puis ferme (trop de clients sur un
  ESP8266) ; écritures partielles et poignée de main vérifiées ; états poussés
  gardés si Jeedom ne répond pas ; port de l'appareil respecté ; démon
  prévenu aussitôt d'un appareil ajouté ou d'un réglage changé.
- Page : balise en trop qui décalait la page de l'équipement ; nouveaux
  boutons actifs sans recharger l'onglet (une fois cette version chargée :
  **rechargez l'onglet du plugin une fois** après la mise à jour).

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
