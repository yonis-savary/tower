# Gestion de fichiers

Un espace de fichiers dans chaque workspace : des dossiers privés par membre, des dossiers
publics partagés par tout le workspace, des uploads volumineux et des liens de partage.

Statut : **non commencé**. Ce document fixe le périmètre décidé ; les points marqués
**(à trancher)** restent ouverts.

## Périmètre

### Dossiers et fichiers

- Chaque fichier et chaque dossier appartient à un workspace. Un élément est soit **privé**
  (visible de son propriétaire seul), soit dans un **dossier public** du workspace.
- Dossiers et sous-dossiers, sans limite de profondeur.
- Un dossier a une icône, choisie parmi une présélection d'une vingtaine, et une couleur,
  choisie parmi une dizaine.
- Renommer, déplacer, supprimer, sur un élément ou sur une sélection.
- Déplacer par glisser-déposer, vers un dossier de la liste ou vers un niveau du fil
  d'Ariane.
- La suppression d'un dossier est récursive et demande une confirmation.
- Les noms sont **sensibles à la casse** : `doc.pdf` et `Doc.pdf` coexistent.
- Un conflit de nom (upload, renommage, déplacement) ouvre une modale qui propose de
  suffixer le nom : `rapport.pdf` devient `rapport (2).pdf`.
- Déplacement en masse : les conflits sont tous détectés **avant** de déplacer quoi que ce
  soit, et la modale les liste. Rien n'est déplacé tant que l'utilisateur n'a pas confirmé.
- Téléchargement d'un fichier, ou d'une sélection / d'un dossier en archive zip.
- Pas de corbeille dans un premier temps : une suppression est définitive. Elle viendra plus
  tard, sous la forme d'un dossier spécial.

### Explorateur

- Vue **Grille** et vue **Liste**, au choix (choix mémorisé par l'utilisateur).
- Fil d'Ariane du dossier courant.
- Tri par nom, taille, date et type.
- Jauge de quota : espace utilisé sur espace disponible, pour l'espace privé et pour les
  dossiers publics.
- Aperçu dans le navigateur (images, PDF, texte, audio, vidéo) des fichiers sous une taille
  limite, fixée par une constante dans le code. Au-delà, le fichier est seulement
  téléchargeable.
- Pas de miniatures : la grille affiche une icône par type de fichier.

### Upload

- Uploads volumineux par morceaux, avec le `LargeUploadManager` de Cube.
- Plusieurs fichiers à la fois, dans une file d'attente affichée par une modale de
  progression.
- Glisser-déposer de fichiers depuis le bureau sur l'explorateur.
- Annulation d'un upload en cours : ses morceaux sont supprimés et sa réservation de quota
  rendue.
- Le quota est vérifié **avant** le premier morceau, sur la taille annoncée par le client.

### Quotas

- Espace privé : **256 Mo par défaut**, par paire workspace / utilisateur. La limite est
  stockée en base et modifiable ligne par ligne.
- Dossiers publics : un quota **propre au workspace**, distinct de ceux des membres. Un
  fichier déposé dans un dossier public consomme ce quota, pas celui de son auteur.
  **(à trancher)** sa valeur par défaut.

### Dossiers publics

- Tout membre du workspace peut tout faire dans un dossier public : lire, ajouter, renommer,
  déplacer, supprimer.

### Liens de partage

- Un lien public, sans compte, vers un fichier. **(à trancher)** vers un dossier aussi.
- Le lien peut être **à usage unique** : il cesse de fonctionner après le premier
  téléchargement.
- Le lien est révocable. **(à trancher)** une date d'expiration, optionnelle ou
  obligatoire.

### Départ d'un membre

- Retirer un membre supprime ses fichiers privés de ce workspace. L'écran de retrait
  l'annonce avec le volume concerné avant confirmation.
- **(à trancher)** ce que deviennent les fichiers qu'il a déposés dans les dossiers
  publics : ils consomment le quota du workspace, les garder semble le plus cohérent.

### Intégration au reste de Tower

- Une section `files` dans `Workspace.sections`, activable comme les autres modules.
- Des lignes d'historique et des événements pour les devices et les webhooks :
  `file.uploaded`, `file.deleted`, `file.shared`... **(à trancher)** la liste exacte.
- Les textes en anglais et en français, et une page dans la documentation.

## Contraintes techniques

### LargeUpload

Le `LargeUploadManager` de Cube ne couvre que le transport des morceaux :

- Un upload n'est rattaché à personne : quiconque connaît son identifiant peut y ajouter
  des morceaux. Tower doit enregistrer en base qui a démarré l'upload, dans quel workspace
  et vers quel dossier, et le vérifier à chaque morceau.
- Sa limite `maxSize` est globale. Le quota par utilisateur et par workspace est à la charge
  de Tower.
- Deux uploads parallèles peuvent chacun passer une vérification de quota et la dépasser
  ensemble : la taille annoncée est **réservée** au démarrage, dans une transaction, puis
  libérée à l'annulation ou remplacée par la taille réelle à la fin.
- Un morceau qui ferait dépasser la taille annoncée est refusé.
- Les uploads abandonnés restent dans `Storage/large-upload-temp` : une tâche planifiée
  appelle `deleteOlderThan()` et rend leurs réservations. C'est une ligne de plus dans la
  crontab de l'hôte (`php do routine:generate`).
- L'assemblage final (`wrap()`) recopie le fichier : il faut temporairement deux fois sa
  taille sur le disque.

### Stockage

- Les fichiers sont stockés sous un identifiant opaque, jamais sous leur nom d'origine, et
  jamais dans `Public/`. Le nom, le dossier et la taille vivent en base.
- Ils sont servis par un contrôleur qui vérifie les droits (ou le lien de partage).
- Le volume `Storage/` doit être sauvegardé avec la base : l'un sans l'autre donne un état
  incohérent.
- La somme des quotas peut dépasser le disque réel. **(à trancher)** une limite globale, ou
  une alerte sur l'espace libre.

### Sécurité

- Le type MIME envoyé par le navigateur n'est pas fiable : il est déterminé côté serveur.
- Un HTML ou un SVG ouvert sur le domaine de Tower peut exécuter du JavaScript : ces types
  ne sont jamais servis en ligne. Tous les fichiers sont servis avec
  `X-Content-Type-Options: nosniff`, et l'aperçu ne concerne que les types sûrs.
- Les noms sont nettoyés : pas de `/`, `\`, `..` seul, ni de caractères de contrôle, et une
  longueur maximale.
- Un lien de partage repose sur un jeton aléatoire long, stocké haché.
- Pas d'antivirus.

## Hors périmètre

- Corbeille (prévue plus tard, en dossier spécial).
- Miniatures.
- Versions de fichiers : remplacer un fichier efface l'ancien.
- Permissions fines par dossier ou par membre.
- Édition en ligne, édition collaborative, synchronisation avec le bureau.
