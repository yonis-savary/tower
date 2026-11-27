# Tower — Architecture

Tower est un médiateur self-hosted entre mes appareils. Il centralise les tâches (pro et
perso, séparées en workspaces), envoie des événements aux appareils connectés, suit des
outils externes (Jira, merge requests) et, à terme, distribue des jobs et déclenche un agent
IA qui tient les tâches à jour.

Ce document décrit ce qui est en place et ce qui est prévu. Les parties prévues sont
signalées par **(prévu)** ; elles restent la cible, mais peuvent changer quand on les
abordera.

## Vue d'ensemble

```
                ┌──────────────────── Tower (serveur) ─────────────────────┐
  Front Vue ──► │  API OpenAPI ──► Noyau : comptes, workspaces, tâches,    │
                │                  sprints, historique                     │
                │        │                 │                    │          │
                │        ▼                 ▼                    ▼          │
                │  Bus d'appareils    Intégrations         Agent IA        │
                │  (événements,       (Bitbucket, Jira)    (prévu)         │
                │   livraisons)                                            │
                └───────┬─────────────────┬────────────────────────────────┘
                        │ WebSocket        │ HTTPS (polling)
                        │ ouvert par       ▼
                        │ l'appareil   Bitbucket, Jira Cloud (GitLab, GitHub prévus)
              ┌─────────┴─────────┐
              │ Connecteur Python │  (un par appareil, lancé à la main ou en service)
              └───────────────────┘
```

| Brique             | Techno                                     |
|--------------------|--------------------------------------------|
| Backend            | Cube                                       |
| Front              | Vue.js, PrimeVue, client généré depuis l'OpenAPI |
| Connecteur         | Script Python livré en zipapp `.pyz`       |
| Intégrations       | Bitbucket Cloud, Jira Cloud ; GitLab, GitHub prévus |
| IA                 | Abstraction de provider, Claude en premier (prévu) |
| Notifications      | Web Push vers les navigateurs (PWA), `minishlink/web-push` |

Vocabulaire :

- le **connecteur** est le script Python qui tourne sur un appareil ;
- une **configuration** (table `access_key`) est l'identité avec laquelle un connecteur se
  connecte ; elle appartient à un workspace et peut servir sur plusieurs appareils ;
- une **intégration** est la liaison d'un workspace à un outil externe (Jira, forge Git) ;
- un **ticket** vient d'un outil externe ; une **tâche** est une unité de travail dans Tower.

## 1. Noyau

### Comptes

- L'inscription est **fermée par défaut** (`REGISTRATION_OPEN` dans `.env`) : sur une instance
  publique, n'importe qui pourrait créer un compte, et réserver l'email d'un futur membre. Fermée,
  un compte se crée par `php do app:create-user <email>`, qui affiche un mot de passe provisoire.
- À l'inscription, un workspace est créé pour l'utilisateur, qui en est le premier membre.
- Un utilisateur change son mot de passe depuis les Paramètres, en donnant l'actuel ; le nouveau
  suit les règles de l'inscription. Ses autres sessions restent ouvertes.
- Un utilisateur n'est jamais supprimé : il est désactivé (`disabled_at`), ne peut plus se
  connecter et perd ses sessions ouvertes. Ses workspaces, tâches et adhésions restent en place.

### Workspace

Le workspace est la frontière d'isolement. Tâches, sprints, configurations, historique,
intégrations et, à terme, jobs et runs IA appartiennent tous à **un** workspace (`pro`,
`perso`, …).

- Un appareil connecté avec une configuration de `pro` ne reçoit que des événements de `pro`.
- Un même appareil physique qui doit servir deux workspaces lance deux connecteurs, avec deux
  configurations.
- Les appareils sont volatils : Tower ne tient pas de registre d'appareils. Un appareil
  n'existe pour Tower qu'à travers ses connexions (voir § Connexions).
- Un workspace peut avoir plusieurs membres, et un utilisateur peut être membre de plusieurs
  workspaces. Tous les membres ont les mêmes droits sur le workspace, sauf sa suppression, réservée à
  son auteur (`workspace.author`) ; il n'y a pas de rôles.
- Un workspace choisit ses **sections** parmi `tasks`, `jira`, `merge_requests`, `events`, `automation`,
  `webhooks`, `documentation` et `agenda` (toutes pour un workspace jamais configuré ; un workspace déjà
  configuré reçoit une nouvelle section décochée). Une section décochée est seulement
  masquée : elle quitte la navbar et les Paramètres, et son adresse renvoie à l'accueil, mais ses données, ses
  synchronisations et ses événements continuent. Accueil, Historique, Paramètres et
  les appareils sont toujours là.
- Un workspace peut avoir une couleur (`accent_color`, `#rrggbb`) : quand il est le workspace
  courant, la page porte une bordure de cette couleur à gauche (sur un téléphone, elle souligne la barre du haut), et la barre du navigateur mobile
  la prend (`<meta name="theme-color">`) ; sans couleur, rien, et la barre garde le bleu de Tower.
  L'application installée garde la couleur de `manifest.json`, lue une fois à l'installation.
- Un workspace peut avoir une icône (`icon`, nul par défaut) : une image PNG ou JPEG d'1 Mio au plus,
  envoyée telle quelle en corps de `PUT /api/workspaces/{id}/icon` (type vérifié sur le contenu) et
  rangée dans `Storage/WorkspaceIcons` sous un nom neuf à chaque envoi ; l'ancienne est effacée, et
  la suppression du workspace l'emporte aussi. `GET /api/workspaces/{id}/icon` la sert, ou à défaut
  le logo de Tower en SVG, généré à la couleur du workspace : la base ne stocke jamais d'icône par
  défaut. Quand le workspace est le courant, cette route sert de favicon à la page, et l'image
  remplace le logo dans la navbar. Son adresse porte une version (`favicon_url`, `?v=`) qui change
  avec l'image ou la couleur, ce qui permet au navigateur de la garder en cache sans limite. Une image
  téléversée a aussi une adresse **publique**, `GET /api/workspace-icons/<fichier>`, sans session :
  le navigateur la cherche pour afficher une notification push, souvent sans page Tower ouverte. Seul
  le nom du fichier la protège, d'où sa part aléatoire de 128 bits (64 pour une image envoyée avant) ;
  une nouvelle image change l'adresse et l'ancienne répond `404`. Pas d'outil MCP :
  un agent n'envoie pas d'image.
- Tout membre peut renommer le workspace, changer sa couleur et son icône, en ajouter un autre par l'e-mail de son compte Tower
  (un compte existant et actif : il n'y a pas d'invitation), retirer un membre ou le quitter.
  Un workspace créé depuis le front n'a que son créateur pour membre et n'est pas sélectionné.
- Un workspace garde toujours au moins un membre : le dernier ne peut pas être retiré, le
  workspace se supprime plutôt. Quitter le workspace courant fait retomber sur un autre.
- Seul l'auteur supprime un workspace, après en avoir retapé le nom, et jamais le dernier dont il
  est membre. Tout ce que le workspace contient part avec lui, pour tous ses membres : tâches,
  sprints, historique, intégrations, webhooks, clés d'accès, tokens API, règles et abonnements push,
  agenda.
  Ceux qui l'avaient sélectionné retombent sur un autre. Aucun outil MCP ne l'expose.

### Tâches et sprints

Une tâche est une unité de travail. Elle peut plus tard être créée depuis un ticket, mais son
statut reste **indépendant** de celui du ticket : le ticket suit la demande dans le workflow
de l'équipe, la tâche suit mon travail. Le statut du ticket sera seulement affiché sur la
tâche, et c'est l'utilisateur qui déplace la tâche.

| Champ          | Rôle                                                    |
|----------------|---------------------------------------------------------|
| `workspace`    |                                                         |
| `sprint`       | nul : la tâche est dans le backlog                      |
| `title`        |                                                         |
| `description`  |                                                         |
| `status`       | voir ci-dessous                                         |
| `position`     | ordre dans sa liste ; l'ordre tient lieu de priorité    |
| `color`        | pastille de couleur (pas encore utilisée)               |
| `jira_issue`   | ticket Jira d'origine, s'il y en a un (voir § Jira)     |
| `link`         | lien unique http(s), facultatif, ouvert depuis la carte |
| `done_at`      | renseigné quand la tâche passe à `done`, vidé sinon     |
| `created_at`, `updated_at` |                                             |

Statuts, fixes : `todo`, `in_progress`, `in_review`, `finalizing`, `done`, et `cancelled`,
statut à part qui n'a pas de colonne sur le tableau.

**Sprints.** Un sprint a un nom et un état `planned`, `active` ou `closed`. Il n'a pas de
durée : il s'ouvre et se clôture à la main.

- Plusieurs sprints peuvent être `active` en même temps ; rouvrir un sprint déjà actif ne fait rien.
- Clôturer un sprint le marque seulement comme terminé : ses tâches restent à leur place, avec
  leur statut. On peut en retirer une tâche (pour la reprendre ailleurs), pas en ajouter.
- L'API permet de rouvrir un sprint clôturé ; le front ne le propose pas encore.
- Pas de suppression : une tâche abandonnée passe en `cancelled`.

Le tableau montre chaque sprint actif en cinq colonnes, du plus ancien au plus récent ; le backlog montre les sprints ouverts et
planifiés puis les tâches sans sprint. Les tâches se déplacent par glisser-déposer, entre les
listes et à l'intérieur d'une liste.

**Timeline.** Un troisième onglet, à côté du tableau et du backlog, montre les tâches traitées
entre deux jours, inclus (un mois glissant par défaut, 92 jours au plus) : une ligne par tâche,
découpée par statut (un trait fin tant qu'elle attend « à faire », une barre quand elle est en
cours, rien une fois finie) et un point par changement de statut sur la période.

- Les statuts tombent dans trois catégories, `todo`, `in_progress` et `done` (`App/Domain/Task/TimelineSpan`).
  Attendre dans un statut à faire n'est pas travailler : une tâche **commence** quand elle quitte
  `todo` pour la première fois, et elle est traitée si elle a été en cours pendant la période ou
  s'est finie pendant celle-ci. Une tâche qui n'a fait que bouger entre statuts à faire n'apparaît pas.
- `GET /api/tasks/timeline?from=&to=` (`App/Domain/Task/TaskTimelineService`) : `done` et `cancelled`
  sont `done`. Sa fin est `done_at`, ou la date d'annulation. L'historique n'étant gardé que 31 jours,
  une tâche plus ancienne n'est placée que par sa création et sa fin.
- Une case « Inclure les tickets Jira sans tâche », proposée quand le workspace a des profils Jira et
  gardée dans le navigateur par workspace, ajoute les tickets **lus dans Jira** :
  `GET /api/jira/worked-issues?from=&to=` cherche (JQL) ceux que le compte du token avait assignés
  pendant la période et dont le statut a changé pendant celle-ci, sur un board suivi ou non, terminés
  ou non, avec leur historique de statuts (`changelog`). Un statut est classé par les **colonnes des
  boards** des profils, comme le kanban : la première est `todo`, la dernière `done`, les autres
  `in_progress` (« Rouvert » rangé dans To Do attend, « Fix » rangé dans Done est fini) ; un statut
  qu'aucun board ne montre prend la catégorie de Jira (`/rest/api/3/status`, lu au besoin). Tower ne
  garde pas les tickets terminés, d'où la lecture dans Jira. Les tickets liés à une tâche sont
  retirés : la tâche les montre déjà. Les 100 mis à jour le plus récemment au plus (`truncated`) ;
  Jira en erreur répond `502`, sans intégration Jira `404`.
- Outils MCP : `get_task_timeline` et `list_worked_jira_issues`.

**Prise de notes.** Une grande modale, ouverte par « Prise de notes » à côté de « Nouvelle
tâche », crée plusieurs tâches d'un coup à partir de notes prises en réunion. Une ligne sans
indentation est le titre d'une tâche, les lignes indentées en dessous (tabulations ou espaces)
sa description, leur indentation commune retirée ; une ligne commençant par `//` est ignorée.
Le sprint se choisit une fois pour tout le lot. La saisie est ponctuelle : la note n'est pas
gardée une fois les tâches créées, seul un brouillon vit dans le `localStorage` (un par
workspace) tant qu'elles ne le sont pas.

- `App/Domain/Task/Notes/TaskNotesParser` est la **seule** implémentation de la syntaxe.
  `POST /api/tasks/notes/preview` renvoie les tâches et les erreurs par ligne sans rien créer :
  le front l'appelle après une pause de frappe pour l'aperçu, qui ne peut donc pas annoncer
  autre chose que ce qui sera créé.
- `POST /api/tasks/notes` crée tout ou rien, dans une transaction, dans l'ordre des notes, en
  `todo` à la fin de la liste. Des notes avec une erreur (titre de plus de 255 caractères,
  description de plus de 20 000, description avant le premier titre, plus de 50 tâches) ou sans
  tâche ne créent rien.
- Chaque tâche a l'historique d'une création ordinaire (`task.created`).
- Outils MCP : `preview_task_notes` et `create_tasks_from_notes`.
- **Raccourci global `N`**, depuis n'importe quel écran : il mène aux tâches et ouvre la prise de
  notes sur le plus récent des sprints actifs (le backlog s'il n'y en a pas). Il est ignoré dans
  un champ de saisie, dans une modale, avec une touche de modification, ou quand la section tâches
  est désactivée (`Frontend/src/utils/globalShortcuts.ts`).

**Liens d'un sprint** (table `sprint_link`) : des pages pratiques à garder sous la main pendant
le sprint (board, maquette, document), une adresse http(s) et un libellé facultatif (sans
libellé, le nom du site). Ils se gèrent dans le backlog tant que le sprint n'est pas clôturé, et
s'affichent par ordre alphabétique sur le tableau et à l'accueil (sprints affichés seulement).

Chaque lien porte l'icône de son site : `GET /api/sprints/links/{id}/icon`, lue par Tower
(`App/Favicons`) dans la page d'accueil du site (`<link rel="icon">`, puis `apple-touch-icon`),
sinon `/favicon.ico`. Elle est gardée **un mois** dans le cache, par origine ; un site sans
icône est redemandé le lendemain. Seules les adresses **publiques** sont lues (résolution DNS
vérifiée puis épinglée, redirections revérifiées, 5 s, 1 Mio par page et 256 Kio par icône) :
un lien ne doit pas faire lire à Tower son propre réseau. Sans icône, le front affiche une
pastille avec l'initiale, d'une couleur tirée du nom du site.

### Documentation

Chaque workspace a sa documentation interne : des pages en Markdown (table `doc_page`), rangées
en arborescence (`parent`, nul pour une page de premier niveau) et classées par **tags**. Elle
s'écrit dans le front ou par un agent (voir § Serveur MCP), par les mêmes routes (`/api/docs/...`).

- **Rendu** côté front : `markdown-it` sans HTML brut (tout HTML écrit dans une page s'affiche
  comme du texte, et les liens `javascript:` sont refusés), code coloré par highlight.js, blocs
  ```` ```mermaid ```` dessinés par mermaid (`securityLevel: 'strict'`), chargé seulement par
  une page qui en contient. L'écran est chargé à la demande pour ne pas alourdir le reste.
- **Versions** (table `doc_page_version`) : chaque changement de titre, de contenu ou de tags
  incrémente `doc_page.version` et garde une copie complète de la page, avec son auteur et le
  nom du jeton d'agent s'il passe par le MCP. Déplacer ou archiver une page n'en crée pas.
  Elles sont gardées **31 jours** (même `HistoryConfiguration` que l'historique), sauf celle où
  la page est, toujours gardée.
- **Conflits** : une modification peut donner la `version` lue ; si la page a changé depuis,
  elle est refusée en `409` au lieu d'écraser. Le front le fait toujours et propose d'écraser.
  `POST /api/docs/pages/{id}/edits` remplace des passages (chacun présent exactement une fois,
  tous ou aucun) : un agent n'a pas à réécrire une longue page pour en changer une ligne.
- **Archivage** : une page archivée (`archived_at`) disparaît de la liste tant que les pages
  archivées sont masquées ; elle reste lisible et se désarchive. Archiver ou désarchiver une page
  fait de même pour toutes ses sous-pages ; la ligne d'historique en donne le nombre (`sub_pages`).
- **Suppression** : réservée au membre qui a créé la page (`created_by`) et à l'auteur du
  workspace, qui a ainsi la main sur toutes les pages, même celles d'un membre parti (sinon `403`).
  Modifier, déplacer et archiver restent ouverts à tous les membres. Elle
  emporte toutes ses sous-pages et leurs versions, après une confirmation qui les compte, et
  demande le droit de supprimer chacune : sinon `403` avec les pages bloquantes, puisque n'importe
  quel membre pourrait déplacer la page d'un autre sous la sienne pour la supprimer. Elle n'est pas exposée aux agents. Supprimer un workspace supprime
  aussi ses pages, leurs versions et ses images (fichiers compris).
- **Images** (table `doc_image`, fichiers dans `Storage/DocImages`) : PNG, JPEG, GIF ou WebP de
  5 Mio au plus, envoyées en base64 dans du JSON (le même appel sert au front et aux agents),
  servies par `GET /api/docs/images/{id}` aux membres du workspace. Jamais de SVG, qui peut
  porter du script. nginx accepte 8 Mo par requête pour cela. Une image n'est pas supprimée
  quand plus aucune page ne la cite.
- Le contenu d'une page est limité à 64 Kio (colonne `TEXT`).

### Agenda

Chaque workspace a son agenda : des éléments (table `agenda_item`) avec un titre, une
description, un début, une fin, un drapeau « toute la journée », un lien http(s) facultatif, une
couleur facultative, des participants (table `agenda_item_participant`), membres du workspace de l'élément, et une
récurrence facultative.

- **Dates** : un élément avec une heure voyage comme un instant, en ISO 8601 avec son décalage
  (`2026-12-01T09:00:00+01:00`), converti à l'heure du serveur à l'entrée (`App/Requests/Rules/Instant`) ;
  l'API répond en ISO 8601 avec le décalage du serveur. Un navigateur et un serveur sur deux fuseaux
  voient donc le même moment. Un élément d'une journée entière voyage comme ses jours
  (`Y-m-d 00:00:00`, sans décalage), lus tels quels des deux côtés. `Y-m-d H:i:s` sans décalage
  reste accepté partout, lu à l'heure du serveur. La fin est
  **exclusive**, comme la lit FullCalendar : un élément d'une journée entière finit à minuit le
  lendemain. Le formulaire montre le dernier jour et ajoute un jour à l'envoi.
- **Plusieurs workspaces sans en changer.** Les routes `/api/agenda/...` ne s'appuient pas sur le
  workspace courant : la liste prend `workspaces` (identifiants séparés par des virgules, le
  courant par défaut), la création prend `workspace`, la modification et la suppression trouvent
  l'élément dans n'importe quel workspace dont l'utilisateur est membre. Un workspace hors de
  portée répond `404`. Un élément reste dans son workspace.
- **Emploi du temps de membres** : la liste prend aussi `participants` (identifiants
  d'utilisateurs séparés par des virgules) et ne garde que les éléments où l'un d'eux participe,
  plus ceux sans participant, qui concernent tout le monde. Le filtre vaut pour tous les
  workspaces lus.
- **Un jeton d'agent** n'atteint que le workspace pour lequel il a été créé
  (`WorkspaceService::reachableIds()`).
- **Participants** : un utilisateur qui n'est pas membre du workspace de l'élément est refusé
  (`422`). Retirer un membre le retire des éléments de ce workspace.
- **Historique et événements** : `agenda.item.created`, `agenda.item.updated`,
  `agenda.item.deleted`, envoyés aux appareils avec l'élément et ses participants (`id`, `email`).
- **Rappel** : un workspace peut régler un délai (`workspace.agenda_reminder_minutes`, de 1 à
  10 080 minutes, nul pour aucun), dans Mes workspaces → Fonctionnalités. Une tâche Cube tourne
  chaque minute (`App/Schedules/agenda.php`) et lance `agenda.item.upcoming`, d'`origin` `agenda`,
  pour chaque élément qui commence ce nombre de minutes plus tard, avec `minutes` en plus dans
  le payload. Un élément d'une journée entière n'a pas d'heure, donc pas de rappel. **Rien n'est
  rattrapé**, comme pour les planifications. Une règle de notification sur ce type en fait une
  notification push ; elle part à tous ceux qui ont la règle, pas seulement aux participants.
- **Planifications dans l'agenda** : `GET /api/agenda/schedules` donne les déclenchements des
  planifications actives sur la période. Le cron est parcouru champ par champ (les jours, puis
  leurs heures et leurs minutes), jamais minute par minute ; une planification qui part plusieurs
  fois dans l'heure donne un seul déclenchement `frequent` par jour, avec son nombre de `runs`.
- **Couleur** : un élément prend la couleur de son workspace, ou la sienne (`#rrggbb`). Le
  formulaire propose une palette fixe de dix couleurs (`AGENDA_COLORS`, côté front), précédée d'un
  cercle vide pour « aucune couleur » ; l'API accepte toute couleur `#rrggbb`, pour un agent.
- **Récurrence** (`App/Domain/Agenda/Recurrence`) : `recurrence` (`daily`, `weekly`, `monthly`, `yearly`, nul pour un
  élément unique), `recurrence_interval` (toutes les N périodes, 1 à 99), `recurrence_weekdays` (jours ISO d'un élément
  hebdomadaire, `1` = lundi, séparés par des virgules ; le jour du début par défaut) et `recurrence_until` (dernier jour
  où une occurrence peut commencer, inclus ; nul : sans fin). L'élément donne la première occurrence ; les autres
  commencent les jours de la règle, à la même heure, et durent autant. Un mois ou une année sans le jour de
  l'élément (un 31, un 29 février) n'a pas d'occurrence. Rien n'est stocké par occurrence : la liste les calcule sur la
  période demandée, sans parcourir les périodes antérieures, et donne **une entrée par occurrence**, avec ses dates,
  l'`id` de l'élément et `occurrence`, le jour où elle commence (heure du serveur), qui la nomme ensuite.
- **Changer une occurrence** : `PUT` et `DELETE` prennent `occurrence` et `scope`. Les dates envoyées sont alors celles
  de l'occurrence. `occurrence` (par défaut) : l'occurrence quitte l'élément (une ligne dans `agenda_item_exception`,
  le jour retiré) et devient un élément unique, pour une modification ; elle disparaît, pour une suppression.
  `following` : l'élément s'arrête la veille (`recurrence_until`), un nouvel élément reprend à partir de l'occurrence avec
  les jours retirés qui suivent, pour une modification. `series` : toutes les occurrences bougent comme celle-ci (le
  début de l'élément d'autant de jours, toutes à sa nouvelle heure) ; une règle renvoyée telle quelle suit le
  déplacement (un hebdomadaire du lundi glissé au mardi se répète le mardi), une règle changée est prise telle
  quelle. Sans `occurrence`, l'élément entier est remplacé ou supprimé. Les éléments détachés ou repris gardent
  leur auteur. L'historique et l'événement d'une occurrence portent `occurrence` et `scope` (et `series`, l'élément
  d'origine, pour une modification) ; le formulaire demande la portée à l'enregistrement, à la suppression et au
  glisser-déposer d'une occurrence, et ne propose pas « cette occurrence » quand la règle change.
- Le rappel vaut pour chaque occurrence, avec `occurrence` dans le payload.
- Une période demandée fait **62 jours au plus**.
- Outils MCP : `list_agenda_items`, `create_agenda_item`, `update_agenda_item` (récurrence et portée comprises). Pas de suppression.

### Recherche

Une recherche globale, ouverte par la touche **F** depuis n'importe quel écran (mêmes garde-fous que
`N` : ignorée dans un champ, une modale ou avec une touche de modification), dans une modale placée
en haut de la page, le champ déjà actif.

- `GET /api/search?q=` (2 à 100 caractères) cherche dans le **workspace courant**, par type et dans
  cet ordre : merge requests (titre, dépôt, branche), tickets Jira (clé, résumé), tâches (titre,
  description), quick-actions (nom, type, description), pages de documentation (titre, contenu).
  **5 résultats par type**, les non terminés d'abord ; `done` marque une merge request fermée, un
  ticket terminé ou qui n'est plus suivi, une tâche terminée ou annulée, une page archivée, affichés
  plus discrètement. Une section que le workspace n'affiche pas n'est pas cherchée.
- Chaque type est une classe qui étend `App/Domain/Search/SearchSource` (son type, sa position, sa section,
  ses champs, ce qui est terminé), trouvée par découverte : ajouter un type, c'est ajouter un fichier.
- Les **workspaces** de l'utilisateur sont cherchés dans le front, par leur nom, et s'insèrent après
  les tâches.
- Flèches haut et bas pour choisir, Entrée ou un clic pour ouvrir : une merge request ou un ticket
  ouvre son lien externe dans un nouvel onglet, une tâche sa fiche (`/tasks?task=`), une page de
  documentation la page (`/docs?page=`), un workspace devient le courant, une **quick-action est
  lancée** aussitôt, avec un toast.
- Outil MCP : `search_workspace`.

### Historique

Toute action notable écrit une ligne dans la table `history` : `workspace`, `type`,
`origin` (`user`, `device`, `integration`, `webhook`, `schedule`, `mcp`, `agenda`, plus tard `agent`), l'utilisateur à l'origine s'il
y en a un, la tâche concernée s'il y en a une, et des `details` en JSON. Les lignes sont
écrites par `HistoryService::log()`.

| Type                          | Quand                                               |
|-------------------------------|-----------------------------------------------------|
| `task.created`, `task.updated` | création, changement de titre ou de description    |
| `task.status_changed`         | changement de statut (`from`, `to`)                 |
| `task.sprint_changed`         | changement de sprint (noms, `null` pour le backlog) |
| `sprint.created`, `sprint.opened`, `sprint.closed` |                                |
| `sprint.link_added`, `sprint.link_updated`, `sprint.link_removed` | `link` : libellé, ou adresse |
| `event.triggered`             | événement lancé à la main, par une quick-action, un webhook ou une planification |
| `pr.*`                        | changements vus par une intégration de forge (voir § Synchronisation et diff) |
| `jira.issue.*`                | changements vus par l'intégration Jira, et transitions faites depuis Tower |
| `device.connected`, `device.disconnected` | connexions des appareils                |
| `key.created`, `key.revoked`  | création et révocation d'une configuration          |
| `key.handlers_updated`, `key.secret_rotated` | handlers modifiés, nouveau secret ; les deux changent le secret |
| `webhook.created`, `webhook.enabled`, `webhook.disabled`, `webhook.deleted`, `webhook.secret_regenerated` | gestion des webhooks |
| `schedule.created`, `schedule.enabled`, `schedule.disabled`, `schedule.deleted` | gestion des planifications |
| `token.created`, `token.revoked` | création et révocation d'un jeton d'agent (voir § Serveur MCP) |
| `agenda.item.created`, `agenda.item.updated`, `agenda.item.deleted` | éléments de l'agenda (`title`, `item`) |
| `agenda.item.upcoming`        | rappel avant un élément (`title`, `item`, `minutes`), voir § Agenda |
| `user.logged_in`, `user.login_failed` | connexion par mot de passe, réussie ou refusée (`email`), écrite dans chaque workspace du compte ; un refus n'a pas d'auteur, et un email inconnu n'écrit rien. Après 5 échecs sur un compte depuis une adresse, ou 50 depuis une adresse, la connexion répond 429 pendant 15 minutes sans rien écrire (`LoginThrottle`) |
| `doc.page.created`, `doc.page.updated`, `doc.page.moved`, `doc.page.archived`, `doc.page.restored`, `doc.page.deleted` | pages de la documentation (`title`, `page`, `version` ; `sub_pages` pour un archivage, un désarchivage ou une suppression) |

Les lignes `task.*`, `sprint.*` et `event.triggered` sont aussi envoyées aux appareils
connectés comme des événements du même type (voir § Événements), comme les lignes `pr.*`,
`jira.issue.*`, `agenda.item.*` et `user.*` (`user` et `email` du compte). Chaque tâche affiche son propre historique, avec l'auteur de chaque changement.

L'historique a, à terme, un second consommateur : **(prévu)** l'agent IA, qui ne lira que les
lignes postérieures à son dernier run.

### Rétention

Une tâche planifiée quotidienne supprime les lignes d'historique et leurs livraisons, ainsi que
les traces d'appels des webhooks, plus anciennes que **31 jours** (configuration PHP, `HistoryConfiguration`). Une autre supprime
les merge requests fermées depuis plus de **30 jours**, une autre les versions de pages de documentation de plus de **31 jours**. Les tâches, les sprints et l'état
courant des intégrations ne sont jamais supprimés par ce nettoyage.

## 2. Appareils

### Principe

L'appareil ouvre lui-même la communication : Tower n'appelle jamais un appareil. Un appareil
dont le connecteur n'est pas lancé est simplement hors ligne. Ça évite toute exposition de
port côté appareil et fonctionne derrière un NAT.

Tower envoie aux appareils :

- des **événements**, envoyés à tous les appareils connectés du workspace dont la
  configuration écoute ce type ; chaque appareil répond quand il a fini, et chaque réponse est
  enregistrée ;
- **(prévu)** des **jobs**, envoyés à un seul appareil, qui répond par un accusé de réception
  puis un résultat. Le principe des jobs est en attente : pour l'instant, il n'y a que des
  événements.

Chaque envoi à un appareil est une **livraison** (table `event_delivery`), avec son propre
statut et sa propre réponse : un événement en a autant que d'appareils contactés.

### Configurations

Une configuration est créée dans le front (page des appareils, onglet « Connecter un
appareil »). Elle donne un `client_id`
(`twr_…`) et un secret de 256 bits, affiché une seule fois ; Tower n'en garde que le SHA-256.
Elle peut servir sur plusieurs appareils et se révoque depuis le front : les appareils qui
l'utilisent sont déconnectés. Elle appartient à son créateur (`access_key.created_by`) : retirer
ce membre du workspace révoque ses configurations, et un créateur désactivé ne laisse plus ses
appareils se connecter ni rien recevoir. Les configurations créées avant n'ont pas de créateur.

Une configuration se **duplique** depuis le front : la modale de création s'ouvre avec ses
abonnements et ses règles de handler, sous le nom « <nom> (copie) », modifiable. La copie est
une nouvelle configuration, avec son propre `client_id` et son propre secret : chaque appareil
se révoque, change de secret ou de handlers sans couper l'autre. Rien n'est ajouté côté API,
la création accepte déjà `listen` et `handlers`.

Chaque configuration porte ses **abonnements** (`listen`) : une liste de motifs, réglée dans
le front, à la création ou ensuite. `*` par défaut, liste vide pour ne rien recevoir.

- Les motifs suivent `fnmatch` : `*` correspond à n'importe quelle suite de caractères, points
  compris (`task.*`, `deploy.start`, `*`).
- Ils sont relus à chaque événement : une modification s'applique sans reconnecter ni
  retélécharger quoi que ce soit.
- Au plus 20 motifs, faits de minuscules, chiffres, points, tirets, `_`, `*` et `?`.

Chaque configuration porte aussi ses **handlers** (`access_key.handlers`) : ce que l'appareil
lance pour chaque événement, construit dans un formulaire du front plutôt qu'écrit à la main.

- Tower les écrit dans la section `handlers` du `config.json` et du client préconfiguré
  téléchargés ; il ne les envoie **jamais** à un appareil, qui reste seul à décider de ce qu'il
  exécute. Sans handler, le fichier n'a pas de section `handlers` (le connecteur refuse une
  section vide).
- Stockés comme une liste de règles (`pattern`, `run`, `response`, `next`), pour garder leur ordre
  et des motifs que JSON transformerait en indices ; `App\Domain\AccessKey\Connector\HandlerRules` les valide avec
  les contraintes de `parse_chain` du connecteur (guillemets fermés, types d'événements, 10
  `next` au plus…) et les traduit au format du connecteur. Le formulaire ne propose que des
  réponses et des payloads à un niveau (champs texte).
- Modifier les handlers **change le secret** de la configuration : ses appareils sont
  déconnectés (motif `secret_rotated`) et l'ancien fichier ne se connecte plus. « Nouveau secret »
  fait la même chose sans toucher aux handlers, pour retélécharger une configuration. Lignes
  d'historique `key.handlers_updated` et `key.secret_rotated`.
- **Lien de téléchargement** : tant que le secret est à l'écran, le front peut demander un lien
  pour `curl` (`config.json` ou client préconfiguré), `GET /api/config-links/<jeton>`, sans
  session. Table `config_download` : SHA-256 du jeton, configuration chiffrée avec `APP_KEY`,
  15 minutes, **un seul usage** (marqué utilisé avant d'être servi, contenu effacé ensuite).
  Changer le secret ou révoquer supprime les liens de la configuration ; la tâche quotidienne
  purge les liens expirés ou servis.

### Événements

Un événement a un type (`deploy.start`, `task.created`…) et un payload, objet JSON. Il est
produit :

- à la main, depuis l'écran Événements, ou par une **quick-action** (événement prédéfini du
  workspace, lancé en un clic) ;
- par l'appel d'un **webhook** du workspace (voir § Webhooks) ;
- par une **planification** du workspace (voir § Planifications) ;
- par une ligne d'historique `task.*` ou `sprint.*`.

L'événement est d'abord écrit dans l'historique, puis envoyé au processus WebSocket, qui le
livre aux connexions dont la configuration l'écoute. Pour chacune, la livraison est écrite
**avant** l'envoi, en `pending`, et son identifiant part avec l'événement. S'il n'y a aucun
abonné connecté, l'événement n'est livré à personne et reste dans l'historique ; un appareil
hors ligne au moment de l'envoi ne le reçoit jamais.

Un événement envoyé depuis une transaction ne part qu'une fois celle-ci validée : le processus
WebSocket écrit la livraison contre la ligne d'historique, qui doit déjà être visible.

**Suggestions de types.** Chaque champ qui demande un type d'événement (lancement manuel,
quick-action, planification, exemple d'une règle) ou une liste de types et de motifs (événements
d'un webhook, motifs d'une règle de notification, écoute d'une configuration) propose des
suggestions en cours de frappe (`EventTypeInput`, sur l'`AutoComplete` de PrimeVue). Dans une
liste, seul le mot sous le curseur est complété, et les types déjà présents sont écartés.

- Les événements de Tower viennent du front : les types de l'historique envoyés aux appareils,
  plus `ai.discuss`, sans ceux d'une section absente du workspace.
- Ceux du workspace viennent de `GET /api/events/suggestions`, par source : quick-actions,
  webhooks, planifications, et les 200 dernières lignes `event.triggered`. Chaque source est une
  classe qui implémente `EventTypeSource`, trouvée par découverte : ajouter une source, c'est
  ajouter un fichier.
- Les champs de motifs proposent aussi `*` et chaque préfixe des types connus (`pr.*`,
  `pr.pipeline.*`).
- Un type présent dans plusieurs groupes n'apparaît que dans le premier : motifs, Tower,
  quick-actions, webhooks, planifications, lancés récemment.

### Webhooks

Un webhook est une adresse qu'un service externe (CI, forge, domotique…) appelle pour lancer des
événements vers les appareils du workspace. C'est la seule entrée de Tower appelée de l'extérieur
sans session.

- Il a un nom, un `uuid` (son adresse : `POST /api/hooks/<uuid>`), un secret et une liste de
  types d'événements (1 à 20, types exacts, sans motif). Chaque appel accepté les lance tous, dans
  l'ordre, avec le corps de l'appel pour payload : un objet JSON de 32 Kio au plus, `{}` si le
  corps est vide.
- Le secret (`whsec_…`, 256 bits) n'est affiché qu'à la création ou à sa régénération, qui
  invalide l'ancien aussitôt. Il est **chiffré** en base avec `APP_KEY`, et non haché : vérifier
  une signature demande le secret lui-même.
- L'appel s'authentifie par l'en-tête `X-Tower-Secret`, ou par une signature HMAC-SHA256 du corps
  dans `X-Hub-Signature-256` ou `X-Hub-Signature` (`sha256=<hex>`), comme l'envoient GitHub et
  Bitbucket. Comparaison en temps constant.
- Un webhook est **sécurisé** par défaut. On peut le créer sans secret, pour des tests ou pour un
  service qui ne sait pas envoyer d'en-tête : l'adresse seule le déclenche alors, comme les
  webhooks entrants de Slack ou Discord (l'uuid porte 122 bits aléatoires). Ce choix se fait à la
  création et ne change plus ; un webhook non sécurisé n'a pas de secret à régénérer, et la liste
  le signale par un cadenas ouvert.
- Un webhook s'active et se désactive depuis le front. Désactivé, il garde son adresse et son
  secret mais refuse les appels.
- Chaque événement lancé est une ligne `event.triggered` d'`origin` `webhook`, avec le nom du
  webhook dans ses `details`, envoyée aux appareils comme un événement manuel.
- **Traces.** Chaque appel à une adresse connue est gardé (table `webhook_call`) : statut, IP,
  `User-Agent`, date, et le corps, seulement pour un appel authentifié (un inconnu ne remplit pas
  la base). Ils sont purgés avec l'historique (31 jours) et supprimés avec leur webhook ; les
  événements déjà lancés restent dans l'historique.

| Statut         | Réponse | Sens                                              |
|----------------|---------|---------------------------------------------------|
| `accepted`     | 204     | événements lancés                                 |
| `unauthorized` | 401     | secret ou signature absent ou faux                |
| `disabled`     | 403     | webhook désactivé (vérifié après l'authentification) |
| `invalid`      | 422     | corps qui n'est pas un objet JSON, ou trop gros   |

Une adresse inconnue répond 404 et n'est pas tracée.

### Planifications

Une planification lance un événement (type et payload, comme une quick-action) à heure fixe.

- Elle est **régulière** (chaque minute, toutes les N minutes, chaque heure, toutes les N heures
  à l'heure pile — N parmi 2, 3, 4, 6, 8, 12, pour garder le même écart la nuit —, chaque jour, certains
  jours de la semaine, chaque mois) ou **ponctuelle** (une date et une heure). Le front traduit ce choix en expression cron, seule
  stockée (`cron`) et seule lue pour la relire en formulaire ; une expression donnée par l'API sous
  une autre forme s'édite telle quelle.
- Un cron n'a pas d'année : une planification ponctuelle garde aussi sa date complète (`run_at`),
  donnée par l'API à la place du cron, qui en est déduit. Elle doit être à venir.
- Une tâche Cube tourne **chaque minute** (`App/Schedules/scheduled-events.php`) et lance les
  planifications actives dont le cron correspond à la minute courante (`CronExpression::matches`),
  à l'heure du serveur. Une ponctuelle ne part qu'à sa minute exacte puis se désactive.
- **Rien n'est rattrapé.** Un déclenchement manqué (scheduler arrêté, run précédent encore en
  cours) est perdu ; une ponctuelle dont la date est passée sans déclenchement est désactivée. Une
  ponctuelle passée ne se réactive qu'en lui donnant une nouvelle date.
- Chaque événement lancé est une ligne `event.triggered` d'`origin` `schedule`, avec le nom de la
  planification dans ses `details`. « Lancer maintenant » part comme un événement manuel et ne
  touche pas à la planification.

### Notifications push

Tower envoie lui-même des notifications natives (téléphone, ordinateur) par Web Push, sans
connecteur. Le périmètre détaillé est dans `ROADMAP/notification.md`.

- Un utilisateur active les notifications **par workspace** sur chaque navigateur
  (`push_subscription`, une ligne par endpoint et par workspace, unique sur ce couple). Un
  navigateur n'a qu'un abonnement push : ses clés sont recopiées dans chaque workspace activé, et
  désactiver un workspace ne désabonne pas le navigateur. À chaque ouverture de Tower, le
  navigateur est revendiqué (`POST /api/push/subscriptions/claim`) : ce qu'un autre utilisateur y
  avait activé est supprimé. Retirer un membre supprime ses navigateurs de ce workspace. Sur iOS,
  seulement dans la PWA ajoutée à l'écran d'accueil.
- Une **règle** (`notification_rule`) appartient à un membre dans un workspace : un nom, des motifs
  (syntaxe des configurations), un titre et un corps. Ce sont des modèles avec les variables des
  handlers du connecteur (`{event}`, `{workspace}` — ici le nom du workspace —, `{payload}`,
  `{payload.author.name}`) ; une variable sans valeur reste écrite telle quelle, et un modèle nul
  ou qui ne donne que du vide prend le texte par défaut. Une notification par
  règle qui correspond, à tous les navigateurs que son utilisateur a activés dans ce workspace.
- Tout événement passe par `EventAnnouncer::announce()` : envoi aux appareils, et
  `PushQueue` quand une règle active existe dans le workspace. Le worker `push`
  (`cube:queue`) relit les règles, vérifie l'adhésion, et envoie (`TTL` 1 h, `Urgency: high`,
  `tag` par règle et type d'événement ; grande icône : l'image téléversée du workspace s'il en a une (son adresse publique, dans `icon`),
  sinon le logo, `icon-192.png` ; icône de la barre
  d'état : `badge-96.png`, le logo-mark blanc sur fond transparent, car Android n'en garde que la
  transparence). `404`/`410` supprime l'abonnement ; `429`, `5xx` et
  une erreur réseau sont réessayés 3 fois au plus.
- Clés VAPID dans `.env` (`php do app:push-keys`) ; sans elles, l'onglet est masqué et rien
  n'est mis en file. Retirer un membre supprime ses règles du workspace.

### Livraisons et réponses

| Statut      | Sens                                                              |
|-------------|-------------------------------------------------------------------|
| `unknown`   | livraison écrite avant que les réponses existent                  |
| `pending`   | envoyée, pas encore de réponse                                    |
| `running`   | le handler de l'appareil a démarré                                |
| `received`  | reçue par un connecteur lancé sans handler                        |
| `succeeded` | handler terminé avec le code de sortie `0`                        |
| `failed`    | handler terminé avec un autre code, introuvable, ou tué au timeout |

- La réponse porte le code de sortie et la sortie du handler, tronquée à 64 Ko.
- Une réponse n'est acceptée que de la **même configuration** que la livraison, même après
  une reconnexion. Une livraison terminée (`received`, `succeeded`, `failed`) garde son
  résultat.
- L'historique affiche, pour chaque ligne envoyée, les appareils contactés avec leur statut,
  leur code de sortie et leur réponse.

### Déconnexion forcée

Depuis la page Appareils, un utilisateur peut déconnecter un appareil. Tower lui envoie un
message `disconnect` : le connecteur s'arrête et **ne tente pas de se reconnecter**. Il faut le
relancer sur l'appareil. Contrairement à la révocation, la configuration reste valide.

### Connexions

Les connexions ouvertes sont tenues en mémoire par le processus WebSocket
(`ConnectedDevices`) : il n'y a pas de table de connexions. Chaque connexion est décrite par
ce que le connecteur envoie à l'ouverture :

| En-tête              | Contenu                                            |
|----------------------|----------------------------------------------------|
| `X-Tower-Client-Id`, `X-Tower-Secret` | la configuration                  |
| `X-Tower-Hostname`   | nom de la machine                                  |
| `X-Tower-Device-Type`| `desktop`, `laptop` ou `mobile`, détecté localement |
| `User-Agent`         | version du connecteur, système, architecture, Python |

La liste des appareils connectés est publiée en direct aux navigateurs du workspace. Les
ouvertures et fermetures sont écrites dans l'historique, avec le motif de fermeture :
`connection_closed`, `revoked`, `secret_rotated` ou `disconnected_by_user`.

Le processus WebSocket vérifie sa connexion à la base toutes les 5 minutes et s'arrête s'il
l'a perdue, pour être relancé par Docker.

**(prévu)** Une table `device_connection`, un `ping` applicatif vers les appareils et la
fermeture des connexions mortes (`ping_timeout`, `stale`, `server_restart`). Le reverse proxy
devant Tower devra alors avoir un délai d'inactivité supérieur à l'intervalle de `ping`
(nginx : `proxy_read_timeout`, 60 s par défaut).

### Protocole

Une seule connexion WebSocket par connecteur, ouverte par lui sur `/devices`, porte les deux
sens. Les messages sont des objets JSON.

Messages de Tower vers l'appareil :

| Type         | Contenu                                                             |
|--------------|---------------------------------------------------------------------|
| `connection` | `uuid` de la connexion, à l'ouverture                               |
| `event`      | `id` (ligne d'historique), `delivery`, `workspace`, `event`, `payload` |
| `disconnect` | `reason` ; arrêt du connecteur, sans reconnexion                    |

Messages de l'appareil vers Tower :

| Type       | Contenu                                                               |
|------------|-----------------------------------------------------------------------|
| `response` | `delivery`, `status` (`running`, `received`, `succeeded`, `failed`), `exit_code`, `output` |

Un message invalide reçoit une réponse `error` ; au-delà de 600 messages par minute, une
connexion est limitée.

**(prévu)** Messages signés pour les jobs : enveloppe `timestamp`, `nonce`, `body` sérialisé
et signature HMAC-SHA256 avec un secret par appareil, horodatage vérifié à 5 minutes près,
nonce à usage unique, comparaison en temps constant. Messages `job`, `cancel`, `ack`,
`progress`, `result`, `ping`/`pong`, avec un `delivery_id` qui rend la livraison idempotente.
Ce mécanisme reste à rapprocher des configurations actuelles quand les jobs seront abordés.

### Sécurité

- TLS obligatoire en production (`wss://`).
- Le secret d'une configuration n'est affiché qu'une fois et seul son SHA-256 est stocké :
  avec 256 bits aléatoires, un hash lent n'apporterait rien et bloquerait la boucle
  WebSocket.
- Le connecteur exécute son handler **sans shell**, en liste d'arguments : le contenu d'un
  événement ne peut jamais devenir une commande ou une option.
- Tower ne peut que **déclencher** ce qu'un appareil a accepté de faire en lançant son
  connecteur avec un handler. Il ne définit jamais la commande exécutée.
- Les tokens des intégrations sont chiffrés en base avec `APP_KEY` (sodium) et ne sont
  jamais renvoyés au front. Changer `APP_KEY` oblige à ressaisir les tokens.

### Reconnexion

La connexion est supposée instable (veille, changement de réseau, redémarrage de Tower).

- Le connecteur se reconnecte avec un backoff exponentiel plafonné et une gigue aléatoire
  (1 s, 2 s, 4 s… jusqu'à 60 s), pour que tous les appareils ne reviennent pas au même
  instant après un redémarrage de Tower.
- Le backoff repart de zéro après une connexion restée ouverte une minute.
- Après un `disconnect`, ou si Tower refuse la configuration, le connecteur s'arrête au lieu
  de se reconnecter.
- Un handler en cours au moment d'une coupure continue localement. Sa réponse, comme toutes
  celles qui n'ont pas pu partir, attend dans le connecteur (1 000 au plus, les plus anciennes
  sont abandonnées) et part à la reconnexion.

### Distribution du connecteur

Le connecteur est livré en un seul fichier zipapp (`tower-connector.pyz`, module `zipapp` de
la stdlib) qui embarque ses dépendances, et se lance avec `python3 tower-connector.pyz`, sans
pip ni venv. Ça évite la PEP 668 (`externally-managed-environment`) sur les distributions
récentes. Il est construit dans l'image Docker et se télécharge depuis la page des appareils.

Contrainte : seules des dépendances en Python pur peuvent être embarquées, une extension
compilée ne serait pas portable d'une architecture à l'autre. `websockets` est en Python pur.
Python 3.10 minimum.

### Lancement du connecteur

```sh
python3 tower-connector.pyz [config.json] [--handler "<commande>" | --handler-file handlers.json]
                            [--handler-timeout 60] [--max-jobs 4] [--no-color]
```

**Configuration.** Un fichier JSON passé en argument, ou embarqué dans un connecteur
**préconfiguré** téléchargé depuis le front (`tower-<configuration>-<workspace>.pyz`, qui se
lance sans argument). Il peut porter ses handlers, ce qui donne un setup en un seul fichier :

```json
{
  "tower_url": "wss://tower.example.com/devices",
  "client_id": "twr_…",
  "secret": "…",
  "handlers": {
    "task.status_changed": "notify-send Tower \"{payload.title} : {payload.to}\"",
    "deploy.*": ["git -C ~/projets/api pull", "make -C ~/projets/api deploy"],
    "*": "python3 ~/tower/log.py"
  }
}
```

Le fichier contient le secret : le partager, c'est donner accès au workspace. Les abonnements
ne sont pas dans ce fichier : ils appartiennent à la configuration, côté Tower.

**Handlers.** Trois sources, la première présente l'emporte :

1. `--handler "<commande>"` : une seule commande pour tous les événements ;
2. `--handler-file fichier.json` : un fichier au format de la section `handlers`, utile avec
   un connecteur préconfiguré, dont la configuration embarquée ne s'édite pas ;
3. la section `handlers` de la configuration.

Format de `handlers` : des motifs d'événements (`fnmatch`, comme les abonnements) en clés, une
commande ou une liste de commandes en valeur, ou un objet `{run, response, next}` (voir plus bas).

- Toutes les clés qui correspondent sont exécutées, dans l'ordre du fichier. Chacune est une
  chaîne indépendante : dans une liste, les commandes s'enchaînent et s'arrêtent au premier
  échec, comme `&&`, sans empêcher les autres clés.
- Aucune clé ne correspond : le connecteur répond `received`.
- `{event}`, `{id}`, `{delivery}`, `{workspace}` et `{payload.champ}` (champs imbriqués avec
  des points) sont remplacés **à l'intérieur d'un argument**, après le découpage : une valeur
  ne peut jamais ajouter d'argument ni de commande. Un nom inconnu reste tel quel.
- Un fichier modifié est relu à l'événement suivant, sans relancer le connecteur ; s'il devient
  invalide, la dernière version valide reste en service. Une configuration embarquée ne
  change pas.

Pour chaque commande :

- elle est découpée comme le ferait un shell, mais exécutée sans shell ; `~` est développé ;
- si elle commence par un fichier `.py` ou `.pyz`, elle est lancée avec le Python du
  connecteur : `--handler ~/tower/tower-handler.pyz` suffit ;
- le message complet arrive en JSON sur son entrée standard ; `TOWER_EVENT`, `TOWER_EVENT_ID`,
  `TOWER_DELIVERY` et `TOWER_WORKSPACE` sont dans son environnement ;
- `--handler-timeout` (60 s par défaut) l'arrête si elle dure trop.

Le connecteur répond `running` au démarrage, puis `succeeded` ou `failed`, avec le code de
sortie du premier échec, ou de la dernière commande. La sortie renvoyée réunit celles des
commandes : la sortie standard de chacune, ou sa sortie d'erreur si la première est vide.
**Réponse et événements suivants.** Un motif en objet prend `run` (commande ou liste),
`response` et `next`, facultatifs ; une autre clé rend le fichier invalide.

- `response` : n'importe quelle valeur JSON, dont chaque texte est complété avec les variables
  des commandes, plus `{stdout}`, `{stderr}`, `{exit_code}` et `{status}`. Un texte qui n'est
  qu'une variable en prend la valeur typée. Elle remplace les sorties des commandes du motif ;
  un objet ou une liste part sérialisé en JSON. Les réponses de plusieurs motifs sont mises bout
  à bout.
- `next` : un événement `{type, payload}` ou une liste, complété de même, envoyé seulement si le
  `run` du motif a réussi, dans le champ `next` de la réponse finale (10 au plus).
- Tower déclenche chacun comme un événement ordinaire, d'origine `device` (ligne
  `event.triggered` avec `device` et `answering`, l'id de la ligne à laquelle il répond),
  seulement sur une réponse finale, et jamais vers le type de l'événement auquel il répond ; les
  boucles plus longues restent à la charge de l'utilisateur. `ConnectorConfiguration(allowNextEvents:
  false)` dans `cube.php` les ignore.

`--max-jobs` (4 par défaut) limite les événements traités en même temps ; les suivants
attendent, et le connecteur continue de recevoir. Sans aucun handler, le connecteur affiche
les événements et répond `received`.

**Affichage.** Chaque message entrant s'affiche derrière une flèche bleue `->`, son payload
coupé à 100 caractères (`…` au-delà), chaque réponse sortante derrière une flèche orange `<-`,
avec son code de sortie, suivie d'une ligne vide. Seul l'affichage est coupé : les handlers
reçoivent le message entier. Quand une commande échoue, sa sortie s'affiche sous la réponse,
comme elle part vers Tower.
`--no-color` retire les couleurs pour les journaux système ; elles sont aussi retirées quand
la sortie n'est pas un terminal ou que `NO_COLOR` est défini.

### Handler de base

Tower fournit un handler prêt à l'emploi, `tower-handler.pyz` (source dans `Handler/src`),
construit dans l'image et téléchargeable depuis la page des appareils. Il n'utilise que la
bibliothèque standard et se branche comme n'importe quel handler :

```sh
python3 tower-connector.pyz config.json --handler ~/tower/tower-handler.pyz
```

ou, dans la configuration : `"handlers": { "ai.discuss": "~/tower/tower-handler.pyz" }`.

Options : `--cwd <dossier>`, le dossier où démarre Claude (le dossier personnel par défaut), et
`--terminal "<commande>"`, le terminal à ouvrir (sinon `$TERMINAL`, puis le premier trouvé
parmi wezterm, kitty, konsole, gnome-terminal, alacritty, foot, xfce4-terminal, xterm ; Terminal
sur macOS, une nouvelle console sur Windows).

**`ai.discuss`** (bouton Discuter d'un ticket Jira) ouvre une fenêtre de terminal qui lance
Claude Code sur le ticket, puis rend la main au connecteur.

- L'identifiant de session est dérivé du workspace et de la clé du ticket (UUID v5) : le premier
  clic crée la session (`--session-id`), les suivants la reprennent (`--resume`). Une session
  existe dès que Claude Code a écrit son fichier `~/.claude/projects/*/<id>.jsonl`.
- Le ticket, relu dans Jira au moment du clic, est donné en **prompt système**
  (`--append-system-prompt`), à la création comme à chaque reprise : Claude a le contexte à
  jour, sans message d'instruction. Il est limité à 100 000 caractères, en retirant d'abord les
  plus anciens commentaires (Linux refuse un argument de plus de 128 Kio).
- Claude démarre toujours dans le même dossier, car une session ne se reprend que là où elle a
  été créée. Le prompt lui donne le nom du projet Jira ; il cherche lui-même le dépôt.
- Le contenu du ticket passe par un fichier (`~/.tower/discussions/<workspace>-<clé>.md`, lisible
  par l'utilisateur seul), jamais par la commande donnée au terminal : seuls des valeurs sûres
  (clé validée, numéro de workspace, dossier) y figurent.
- La fenêtre est lancée détachée, sans les tubes du connecteur, qui attend leur fermeture pour
  répondre. Sous Linux, il faut une session graphique (`DISPLAY` ou `WAYLAND_DISPLAY`) : un
  connecteur lancé en service sans affichage répond `failed` avec l'explication.

Les autres événements sont ignorés, avec une réponse `succeeded`.

## 3. Intégrations

Une intégration relie un workspace à un outil externe. Les drivers d'une même famille sont
des siblings sous une même abstraction (`PullRequestProvider` pour les forges) : chacun
traduit les réponses de son API en objets valeur communs (`PullRequestData`), avec des
statuts locaux à Tower, si bien que le diff, l'historique et les écrans ne connaissent pas
l'outil d'origine.

| Type        | Famille          | Écran                        | État     |
|-------------|------------------|------------------------------|----------|
| `bitbucket` | merge requests   | Merge Requests               | en place |
| `jira`      | tickets          | Jira                         | en place |
| `gitlab`    | merge requests   | Merge Requests               | prévu    |
| `github`    | merge requests   | Merge Requests               | prévu    |
| `figma`     | maquettes        | aucun : lue par les agents   | prévu    |

Pour l'instant, un workspace a au plus une intégration par type.

Les identifiants (tokens d'API) sont **chiffrés en base** avec `APP_KEY` (sodium).

### Synchronisation et diff

Une tâche planifiée interroge chaque intégration toutes les 5 minutes ; le front permet aussi
de lancer une synchronisation. À chaque passage :

1. le driver récupère l'état courant ;
2. Tower le compare à l'état stocké, élément par élément ;
3. chaque différence écrit une ligne d'historique d'`origin` `integration` ;
4. le nouvel état remplace l'ancien, dans une transaction.

Chaque différence est un événement **précis**, avec ses valeurs avant et après, plutôt qu'un
`changed` générique qui liste des champs. Les anciens types `pr.added`, `pr.changed`,
`pr.removed`, `jira.issue.added`, `jira.issue.changed` et `jira.issue.removed` n'existent plus ;
leurs lignes déjà écrites s'affichent sous leur type brut jusqu'à leur purge.

Merge requests :

| Type                        | Quand                                                          |
|-----------------------------|----------------------------------------------------------------|
| `pr.opened`                 | une merge request entre dans l'ensemble suivi                  |
| `pr.merged`                 | elle en sort, fusionnée                                        |
| `pr.declined`               | elle en sort autrement : refusée, remplacée, ou introuvable (`state` le précise, `null` si introuvable) |
| `pr.pipeline.failed`        | son pipeline passe en échec                                    |
| `pr.pipeline.succeeded`     | son pipeline passe en réussite                                 |
| `pr.review.approved`        | un relecteur approuve (une ligne par relecteur)                |
| `pr.review.changes_requested` | un relecteur demande des changements (une ligne par relecteur) |
| `pr.commented`              | son nombre de commentaires augmente (`count` : les nouveaux)   |
| `pr.pushed`                 | son commit source change                                       |
| `pr.ready`                  | elle quitte l'état brouillon                                   |
| `pr.retargeted`             | sa branche cible change                                        |

Tickets Jira, seulement pour ceux de l'utilisateur du token : les autres tickets des profils
s'affichent, mais n'émettent rien. Un ticket montré par plusieurs profils n'est annoncé qu'une fois.

| Type                        | Quand                                                          |
|-----------------------------|----------------------------------------------------------------|
| `jira.issue.assigned`       | un ticket d'un profil devient le sien : nouveau, ou réassigné à lui |
| `jira.issue.done`           | un des siens devient terminé : catégorie « terminé », ou statut de la dernière colonne du board d'un de ses profils |
| `jira.issue.unassigned`     | un des siens ne l'est plus : réassigné (`assignee`, `null` si plus personne), sorti de tous les profils, ou supprimé (`status` à `null`) |
| `jira.issue.status_changed` | son statut change (statuts et catégories avant et après)       |
| `jira.issue.sprint_changed` | son sprint change (`null` : sans sprint)                       |
| `jira.issue.priority_changed` | sa priorité change                                           |
| `jira.issue.updated`        | son résumé ou son type change (`fields` les liste)             |

Un passage de pipeline en cours ou en attente, un changement de titre de merge request ou une
nouvelle approbation retirée n'émettent rien : l'état stocké est mis à jour sans événement. Les
commentaires Jira ne sont pas suivis.

**Payload.** Chaque payload se suffit à lui-même : un handler n'a pas à rappeler Tower ni
l'outil externe.

- Toujours : `source` (`bitbucket`, `jira`), `url`, et pour une merge request `id`,
  `repository`, `title` ; pour un ticket `key`, `summary`.
- Pour un changement de valeur : `from` et `to`, plus les champs propres au type (`reviewer`
  pour une relecture, `count` pour les commentaires, `from_category` et `to_category` pour un
  statut).

**Détection.** Chaque type de changement est une petite classe qui implémente le
contrat de sa famille (un pour les merge requests, un pour les tickets) : elle reçoit l'état
stocké et l'état lu d'un même élément, et rend zéro, un ou plusieurs changements. La
synchronisation les trouve par découverte (`Bunch::fromImplements`), sans liste à tenir :
ajouter un type d'événement, c'est ajouter un fichier. L'entrée et la sortie de l'ensemble
suivi (`pr.opened`, `pr.merged`, `jira.issue.assigned`, `jira.issue.done`…) restent décidées par
la synchronisation, qui seule sait qu'un élément apparaît ou disparaît.

- Le **premier passage** d'une intégration sert de référence et n'écrit rien, sinon il
  produirait une ligne par élément existant. Changer de compte ou de workspace externe efface
  l'état stocké et refait une référence.
- Un passage en erreur (token expiré, API indisponible) n'écrit rien et ne remplace pas
  l'état stocké ; l'erreur est enregistrée sur l'intégration et visible dans les paramètres.
- Les écrans lisent l'état stocké, pas l'API : l'affichage ne dépend pas de la disponibilité
  de l'outil externe.
- Les lignes sont écrites dans la transaction du passage, puis envoyées aux
  appareils une fois la transaction validée, comme les lignes `task.*` : le processus
  WebSocket écrit les livraisons contre elles. Les abonnements filtrent comme pour les autres
  événements (`pr.pipeline.*`, `jira.issue.status_changed`…).
- « Au fil du temps », c'est l'historique : chaque changement y est une ligne datée, avec son
  avant et son après, gardée 31 jours. L'état stocké sert d'« avant » au passage suivant ; il
  n'y a pas d'instantanés.
- Une action faite depuis Tower émet le même type qu'un changement vu par la synchronisation,
  d'`origin` `user` : une transition Jira donne un `jira.issue.status_changed`.

### Merge requests

- **Bitbucket Cloud**, authentifié par l'e-mail du compte Atlassian et un API token (droits
  `read:user:bitbucket`, `read:pullrequest:bitbucket`, `read:repository:bitbucket`).
- Ensemble suivi : les merge requests ouvertes **dont l'utilisateur du token est l'auteur**
  dans le workspace Bitbucket configuré, sans liste de dépôts. Lecture seule.
- **Lecture à la demande**, pour les relectures : toute merge request d'un dépôt du workspace
  Bitbucket configuré que le compte peut lire, suivie ou non, se lit dans la forge sans rien
  stocker, comme un ticket Jira. Le dépôt est donné par son slug seul (`api` pour `team/api`) :
  la lecture ne sort jamais du workspace de l'intégration.
  - `GET /api/repositories/{dépôt}/pull-requests` (`search_repository_pull_requests`) : celles
    du dépôt, de la plus récemment modifiée à la plus ancienne, filtrées par branche source
    (`branch`), par état (`state`, `open` par défaut) et en nombre (`limit`, 20 par défaut,
    50 au plus). `id` est celui de la merge request suivie, `null` sinon.
  - `GET /api/repositories/{dépôt}/pull-requests/{numéro}` (`get_repository_pull_request`), et
    `GET /api/pull-requests/{id}` (`get_pull_request`) pour une merge request suivie : sa
    description, les fichiers qu'elle change (lignes ajoutées et retirées ; `null` si le token n'a
    pas le scope `read:repository`, le reste est quand même lu) et ses commentaires
    en Markdown, du plus ancien au plus récent, sans les supprimés ni les brouillons. Une réponse
    porte l'id du commentaire auquel elle répond (`parent`), un commentaire en ligne son fichier
    et sa ligne, et chacun dit si son fil est résolu ; `unresolved` ne garde que les fils ouverts.
  - `GET /api/repositories/{dépôt}/pull-requests/{numéro}/diff` (`get_pull_request_diff`) : son
    diff unifié en texte, depuis le point où sa branche source a quitté la cible. Au-delà de
    256 Kio il répond `413` : `path` le réduit à un fichier ou à un dossier, découpage fait par
    Tower dans le diff, quelle que soit la forge.
- Une merge request qui disparaît de la liste est relue une dernière fois pour connaître son
  état final ; les fermées restent affichées 30 jours.
- Les statuts de pipeline ne sont relus que si le commit source a changé ou si le pipeline
  n'était pas terminé.
- Affichage en **arbre** : une merge request dont la branche cible est la branche source
  d'une autre merge request ouverte du même dépôt en est l'enfant. Les racines sont les merge
  requests qui visent une branche sans merge request ouverte (`main`, `develop`…).
- Un onglet par dépôt. En tête, un graphique : chaque branche cible de base (`main`…) est un
  nœud en pointillés, chaque merge request un cercle coloré selon son pipeline, avec ses
  approbations et son nombre de commentaires. Une pile de merge requests reste sur une ligne, un
  embranchement descend d'une ligne. Une boucle de branches qui se visent l'une l'autre est
  coupée pour l'affichage.
- Chaque merge request s'ouvre sur la forge, dans un nouvel onglet.

### Jira

- **Jira Cloud**, authentifié par l'e-mail du compte Atlassian et un API token. L'adresse du
  site doit être de la forme `https://<nom>.atlassian.net` : le token n'est envoyé qu'à un site
  Jira Cloud.
- Les **profils Jira** (`jira_profile`) disent quoi suivre : chacun un board, par son identifiant
  (le nombre de son adresse, `…/boards/42`), restreint par une condition JQL facultative
  (1000 caractères, sans `ORDER BY` : elle est mise entre parenthèses dans la requête de Tower,
  qui porte le seul tri), et un nom facultatif ; sans nom, il prend celui du board, relu à
  chaque passage. Deux profils peuvent suivre le même board avec des filtres différents.
- Ensemble suivi : **tous** les tickets non terminés de chaque profil (API Jira Software,
  `board/{id}/issue`), avec leur assigné (identifiant, nom, avatar). Un ticket est stocké une
  fois, quel que soit le nombre de profils qui le montrent : `jira_issue_profile` les relie,
  sous-tâches comprises, et est réécrite à chaque passage. Un ticket est terminé si son statut
  est dans la catégorie « terminé » de Jira, **ou** dans la dernière colonne du board d'un de
  ses profils : c'est elle qui fait foi, un board pouvant y ranger des statuts que Jira classe
  « en cours » (un « Fix », par exemple). Les statuts de cette colonne sont exclus de la
  requête et gardés sur le profil (`done_statuses`). Chaque passage lit aussi le compte du
  token (`/myself`), gardé dans les `settings` : c'est « moi » pour le filtre, le compteur et
  les événements. Le premier passage qui connaît le compte sert de nouvelle référence.
- Un profil ajouté est sa propre référence : les tickets qu'il est le seul à montrer à sa
  première lecture ne sont pas annoncés (`synced_at` du profil encore vide). Changer son board
  ou son filtre le remet à zéro de la même façon. Supprimer un profil, ou changer son board ou
  son filtre, retire tout de suite et sans les annoncer les tickets qu'aucun autre profil ne
  montre : rien ne s'est passé dans Jira, c'est ce qui est suivi qui a changé. Ceux qui ont une
  tâche restent, hors du kanban.
- Un profil en erreur (board inconnu, JQL refusée par Jira) fait échouer tout le passage, avec
  son message dans `last_error` : l'état stocké reste celui du passage précédent.
- Chaque carte porte la pastille de son assigné : son avatar Jira, ses initiales si l'avatar ne
  se charge pas, une silhouette s'il n'y a personne. La case « Mes tickets seulement », avant le
  choix des sprints, ne garde que les tickets du compte et masque alors les pastilles ; elle est
  décochée par défaut et gardée dans le navigateur, par workspace. Le compteur de la navbar ne
  compte que les tickets du compte.
- Le kanban montre un profil à la fois, choisi dans une liste affichée dès qu'il y en a deux,
  et gardé dans le navigateur par workspace. Il reprend les colonnes du board du profil, dans
  son ordre, même vides, avec les statuts que chacune regroupe (`board/{id}/configuration`).
  Elles sont relues à chaque synchronisation et gardées sur le profil (`columns`). Un ticket
  dont le statut n'est dans aucune colonne, que Jira masque, est affiché dans une colonne à
  part, « hors des colonnes du board ».
- Glisser un ticket dans une autre colonne lit les transitions autorisées par le workflow
  depuis son statut actuel et garde celles qui mènent à un statut de la colonne. Une seule :
  elle est appliquée. Plusieurs : Tower demande le statut d'arrivée. Aucune, ou une transition
  refusée par Jira (champs obligatoires…) : le ticket revient à sa place, et Tower affiche
  l'erreur avec un lien vers le ticket dans Jira.
- Une transition faite depuis Tower met à jour le ticket stocké tout de suite et, si c'est un
  ticket de l'utilisateur, écrit une ligne `jira.issue.status_changed` d'`origin` `user`,
  envoyée aux appareils ; la synchronisation suivante n'y voit donc plus de différence. Un ticket passé en « terminé »
  reste dans la colonne jusqu'à la synchronisation suivante, qui le sort de l'ensemble suivi.
- Chaque ticket porte son sprint ouvert, s'il en a un (champ `sprint` de l'API Jira Software).
  Une liste à cases à cocher choisit les sprints affichés, parmi ceux où l'utilisateur a des
  tickets, plus « Sans sprint » ; les sprints actifs viennent en premier. Le choix est gardé
  dans le navigateur, par workspace. Ce sont les sprints masqués qui sont retenus : un nouveau
  sprint est affiché par défaut.
- **Sous-tâches** : la lecture d'un board demande aussi le champ `subtasks` de chaque ticket,
  sans appel de plus (le filtre du board les exclut souvent). Elles sont rangées dans
  `jira_issue` avec l'id de leur ticket dans `parent`, terminées comprises. Jira ne donne ni
  leur projet, ni leur sprint, ni leur assigné : elles prennent le projet et le sprint de leur
  parent et restent sans assigné, donc jamais annoncées. Elles quittent l'ensemble suivi avec
  leur parent (gardées hors du kanban et sans parent si elles ont une tâche). La case
  « Sous-tâches », décochée par défaut et gardée dans le navigateur par workspace, les
  affiche juste après leur parent, avec la même carte qu'un ticket (mêmes actions : copier la
  clé, en faire une tâche, merge request, discussion) et un label « Enfant de <clé> ». Elles
  changent de statut par glisser-déposer, comme un ticket ; la sous-tâche ne reprend de la
  lecture qui suit que son statut, pas le sprint ni l'assigné que Jira lui donne seule.
- Chaque ticket s'ouvre dans Jira, dans un nouvel onglet.
- Un ticket peut être transformé en tâche, à la fin du backlog : son titre est `<clé> <résumé>`,
  sa description le lien du ticket. Un ticket a au plus une tâche. La tâche affiche le statut
  Jira du ticket, sans synchronisation des statuts.
- Un ticket qui sort de l'ensemble suivi est relu une dernière fois. S'il a une tâche, il est
  gardé avec ce dernier statut, hors du kanban (`tracked` à faux) ; sinon il est supprimé.
- Chaque carte porte des actions en icônes : créer la tâche, ou l'ouvrir si elle existe, et
  **discuter**. Discuter relit le ticket dans Jira à ce moment-là et lance un événement
  `ai.discuss` vers les appareils du workspace, comme un événement manuel (ligne
  `event.triggered`). Son payload : `source` (`jira`), `key`, `url`, `summary`, `project`,
  `issue_type`, `priority`, `status`, `sprint`, `assignee`, `reporter`, `labels`, `description`
  et `comments` (les 20 derniers : `author`, `created`, `body`). La description et les
  commentaires sont en texte wiki Jira (API v2), pas en document ADF.
- Supprimer l'intégration supprime ses profils et ses tickets ; leurs tâches restent, sans lien.
  Changer de compte fait de même pour les tickets et repart d'une nouvelle référence, en gardant
  les profils ; changer de site supprime aussi les profils, dont les boards sont numérotés par
  site.

## 4. Agent IA (prévu)

### Abstraction de provider

Un contrat `AiProvider` expose une seule opération : exécuter une conversation avec outils
et rendre le résultat (messages, appels d'outils, consommation de tokens). Chaque fournisseur
est un driver sibling sous cette abstraction ; Claude est le premier. Le choix du provider
et du modèle par usage (tri, raisonnement) relève de la configuration.

### Outils exposés à l'agent

Les outils sont la seule voie d'action de l'agent, tous bornés au workspace du run :

| Outil              | Effet                                                       |
|--------------------|-------------------------------------------------------------|
| `search_tasks`     | recherche filtrée, renvoie des résumés                      |
| `get_task`         | détail d'une tâche                                          |
| `create_task`      |                                                             |
| `update_task`      |                                                             |
| `list_devices`     | appareils du workspace et état de connexion                 |
| `trigger_event`    | lance un événement vers les appareils abonnés               |
| `write_memory`     | remplace la note de mémoire du workspace                    |

### Économie de tokens

L'objectif : un run sans nouveauté coûte zéro token, un run normal quelques centaines.

1. **Le déterministe hors IA.** Le diff des intégrations et les livraisons sont calculés sans
   modèle ; l'agent ne reçoit que leurs résultats sous forme de lignes d'historique.
2. **Déclenchement par événement.** Il n'y a pas de planification de l'agent : c'est
   l'arrivée d'une ligne d'historique dans un workspace qui programme un run. Rien de
   nouveau, aucun appel.
   - **Regroupement** : un run démarre après une courte période sans nouvelle ligne, et au
     plus tard après un délai maximal. Une rafale (dix tâches créées d'un coup, un passage
     d'intégration chargé) produit un seul run, pas dix.
   - **Pas d'auto-déclenchement** : les lignes d'`origin` `agent` ne programment pas de run,
     sinon chaque modification faite par l'agent le relancerait en boucle. Elles sont lues au
     run suivant.
3. **Contexte compact.** Le prompt contient uniquement :
   - les lignes d'historique depuis le dernier run ;
   - les tâches non terminées, en résumé (`id`, titre, ancienneté) ;
   - la note de mémoire du workspace.

   Le détail se récupère à la demande via les outils.
4. **Mémoire résumée.** En fin de run, l'agent réécrit une note courte qui remplace
   l'historique pour le run suivant.
5. **Prompt caching.** Prompt système et définitions d'outils sont stables et placés en tête
   pour être mis en cache.
6. **Deux étages.** Un modèle léger trie (`nothing_to_do` ou `escalate`) ; le modèle principal
   n'est appelé qu'en cas d'escalade.
7. **Budget plafond** par workspace et par jour ; au-delà, les runs sont suspendus jusqu'au
   lendemain et une ligne d'historique le signale.

Chaque run est persisté (`agent_run`) avec son curseur, les tokens consommés (entrée,
cache, sortie), les outils appelés et l'issue, pour pouvoir mesurer et ajuster.

## 5. Front

### Navbar

Navbar verticale à gauche, toujours visible, au-delà de 48rem de large.

**Sur un téléphone** (48rem et moins, `MobileNav`), elle laisse place à deux barres, au format
des applications natives :

- **en haut**, le sélecteur de workspace (icône et nom) et l'indicateur Appareils, en pastille ;
  la couleur du workspace souligne la barre ;
- **en bas**, une barre d'onglets fixe : Accueil, les trois premières sections du workspace (avec
  leurs compteurs), et **Plus**, qui ouvre une feuille montant du bas avec le reste (autres
  sections, Appareils, Historique, Documentation, Paramètres, e-mail et déconnexion). Un compteur
  rangé dans la feuille s'additionne sur le bouton Plus.

Les deux barres tiennent compte des zones sûres (encoche, barre d'accueil : `viewport-fit=cover`).
Les éléments, compteurs et actions sont les mêmes partout (`utils/navigation.ts`).

À la même largeur, le reste du front s'adapte :

- marges de page et de panneau resserrées (`--page-gutter`, `--panel-padding` dans `style.css`) ;
- les modales deviennent des feuilles montant du bas, sur toute la largeur ;
- les kanbans (Accueil, Tâches, Jira) montrent une colonne à la fois, aimantée au défilement, la
  suivante dépassant du bord ;
- au doigt, une carte se déplace après un **appui long** (250 ms, `utils/touchDrag.ts`) : un
  glissé fait défiler la page ou le kanban ;
- les onglets verticaux (Paramètres, Documentation) deviennent une rangée de pastilles qui défile ;
- chaque ligne de l'Historique devient un bloc : libellé, détail, puis date.

En haut, les éléments principaux ; en bas, les secondaires, puis l'e-mail et la déconnexion.
Tâches, Jira, Merge Requests et Événements n'apparaissent que si le workspace courant a cette
section (voir § Workspace). Tâches, Jira et Merge Requests portent un compteur : tâches restantes
des sprints actifs, tickets non terminés de l'utilisateur, merge requests ouvertes.

| Élément            | Contenu                                                            |
|--------------------|--------------------------------------------------------------------|
| Workspace          | tout en haut : sélecteur du workspace courant, avec une dernière option « Gérer mes workspaces » |
| Appareils          | indicateur permanent, voir ci-dessous                              |
| Accueil            |                                                                    |
| Agenda             | section `agenda` : agenda de tous les workspaces de l'utilisateur  |
| Tâches             | section `tasks` : tableau et backlog                               |
| Jira               | section `jira` : kanban d'un profil Jira                           |
| Merge Requests     | section `merge_requests`                                           |
| Événements         | section `events` : lancement manuel et quick-actions               |
| Automatisations    | section `automation` : planifications                              |
| Webhooks           | section `webhooks`                                                 |
| Documentation      | section `documentation` : pages du workspace                       |
| Historique         | en bas                                                             |
| Aide / API         | en bas, voir § Écrans                                              |
| Paramètres         | en bas                                                             |

### Indicateur Appareils

Toujours présent dans la navbar. Il affiche l'état de la connexion temps réel du navigateur
et le nombre d'appareils connectés au workspace, mis à jour en direct.

Un clic ouvre la **page des appareils** (`/connected-devices` : `/devices` est le chemin WebSocket des
connecteurs, souvent derrière le même domaine), à deux onglets, l'onglet choisi dans l'adresse (`?tab=`). *Appareils connectés* : pour chaque
appareil, une icône de son type, son nom de machine, sa configuration, son système, l'heure de
connexion et un bouton **Déconnecter**. *Connecter un appareil* : voir ci-dessous.

**(prévu, avec les jobs)** Une couleur selon l'état du workspace (job en attente d'un choix
d'appareil, job échoué, job en cours), et dans la modale les jobs en attente d'un choix et
les jobs échoués récents.

### Circuit

Le premier bloc de l'accueil dessine le workspace comme un circuit électronique, dans un SVG
écrit à la main (`HomeFlow`, placement dans `utils/flowLayout.ts`). Il n'est monté qu'à partir
de 64rem de large : ni affiché ni abonné aux signaux sur mobile.

- **Puces** : Tower au centre ; à gauche les sources, par catégorie (Interface, Agents IA : un
  jeton du workspace par puce, de tous les membres, Intégrations, Webhooks, Planifications **régulières** : une ponctuelle n'est pas une puce, l'accueil la liste à part, Agenda : une puce « Rappel » quand la section est active et le délai réglé ; une catégorie vide ne prend pas de place) ; à droite les sorties :
  les appareils **actuellement connectés** (WebSocket) et les règles de notification de
  l'utilisateur (Push, si les clés VAPID sont en place). Chaque puce a une LED : verte, grise si
  désactivée ; pour un appareil, la couleur de sa dernière livraison pendant 4 s.
- **Pistes** : horizontales, biseautées à 45°, verticales. La piste qui monte ou descend le plus
  tourne au plus près de Tower, ce qui évite tout croisement.
- **En direct**, une piste s'illumine en vert pendant une seconde, comme le témoin d'activité :
  celle de la source à chaque événement envoyé aux appareils (la source vient de l'origine du
  signal), puis celles des règles dont un motif correspond ; celle d'un appareil en bleu quand sa
  livraison part, en vert quand il répond, en rouge s'il échoue ; en rouge celle d'un webhook dont
  l'appel est refusé ; celle d'un agent à chaque appel d'outil, en rouge s'il échoue (l'évènement
  qu'il lance n'allume pas en plus la puce Interface).
- Au-delà de **12 puces** (Tower exclue), le circuit reste affiché mais statique, avec un message
  qui l'explique : animer chaque événement coûterait trop cher.
- Un clic sur une puce ouvre son écran (Jira, Merge Requests, Webhooks, Automatisations, Agenda, Paramètres → Agents IA).
- Le bloc se replie sur son titre ; le choix est gardé dans le navigateur. Replié, le circuit
  n'est pas dessiné et rien n'est animé.

Les signaux du workspace portent ce qu'il faut pour cela : `history.created` (`history_type`,
`history`, `origin`, et pour un événement `event` et l'id du webhook ou de la planification en
`source`), `delivery.updated` (`history`, `device` : la connexion, `status`), `webhook.refused`
(`webhook`), `mcp.called` (`token`, `tool`, `ok`).

### Écrans

| Écran              | Contenu                                                              |
|--------------------|----------------------------------------------------------------------|
| Accueil            | sur écran large, en premier et sur toute la largeur, le **circuit** (voir § Circuit) ; puis les sprints en cours, les quick-actions, le bloc **À venir** et les derniers événements. À venir mêle, du plus proche au plus lointain et huit au plus : les éléments de l'agenda du workspace courant sur sept jours, en cours ou à venir, où l'utilisateur participe ou sans participant (comme l'agenda par défaut), avec leur couleur ; et les planifications ponctuelles activées et pas encore passées. Chaque ligne donne sa date, le délai restant (ou « en cours ») et mène à l'Agenda ou aux Automatisations ; le bloc disparaît quand il n'y a rien |
| Tâches             | onglet Tableau (un kanban de cinq colonnes par sprint actif, repliable, choix gardé dans le navigateur par workspace ; une tâche se glisse d'un sprint actif à l'autre ; annulées à part) et onglet Backlog (sprints ouverts et planifiés, backlog, sprints clôturés repliés) ; fiche de tâche avec son historique |
| Événements         | deux cartes côte à côte, l'une sous l'autre quand la place manque : lancer un événement (type et payload JSON) ; créer, lancer et supprimer des quick-actions |
| Automatisations    | onglets, choisi dans l'adresse (`?tab=`) ; onglet **Planifications** : tableau des planifications : interrupteur actif/inactif (bloqué pour une ponctuelle passée), nom, événement, fréquence en clair, dernier déclenchement ; création et modification (nom, événement, régulière avec sa fréquence ou ponctuelle avec sa date), lancement immédiat, suppression ; rechargé quand un événement est lancé |
| Webhooks           | tableau des webhooks : interrupteur actif/inactif, cadenas ouvert pour un webhook non sécurisé, adresse à copier, événements, dernier appel ; création (nom, événements, sécurisé ou non, avec un avertissement) et modification (nom, événements), régénération du secret, suppression ; secret affiché une fois avec un exemple `curl` ; modale des appels (statut, IP, `User-Agent`, corps), par pages de 50 |
| Merge Requests     | bouton « Synchroniser » (lit Bitbucket aussitôt, comme celui de l'écran Jira pour Jira, puis recharge la liste ; une erreur de synchronisation s'affiche en toast) ; un onglet par dépôt : graphique en arbre des merge requests ouvertes, puis leur liste (approbations, commentaires, brouillon, pipeline), puis celles du dépôt fermées récemment |
| Historique         | tableau compact des lignes du workspace (une ligne chacune : type, détail tronqué, date ; un bloc par ligne sur téléphone), les plus récentes d'abord, par pages de 50 ; pour une ligne envoyée aux appareils, les livraisons avec statut, code de sortie et réponse |
| Paramètres         | onglets verticaux à gauche, contenu à droite, onglet choisi dans l'adresse (`?tab=`) : **Compte** (changement de mot de passe) ; **Interface** (langue : auto, français ou anglais ; thème : auto, clair ou sombre ; gardés dans le navigateur, appliqués sans recharger) ; intégrations Bitbucket et Jira, chacune affichée si sa section est active : workspace ou site, e-mail, token, synchronisation, profils Jira (board, nom, filtre JQL ; ajout, modification, suppression, chacun suivi d'une synchronisation), état de la dernière synchronisation ; bloc « Vider une section », toujours visible, qui supprime d'un coup l'intégration Jira ou Bitbucket et ses données |
| Jira               | tickets non terminés d'un profil, choisi dans une liste, avec la pastille de leur assigné, ou seulement les siens, en kanban avec les colonnes de son board ; transition par glisser-déposer, ouverture dans Jira, création de tâche ; option « Merge requests » (gardée dans le navigateur par workspace, proposée avec la section `merge_requests`) : au bas de chaque carte, une ligne par merge request ouverte du ticket (clé Jira dans la branche ou le titre) avec son numéro en lien externe, son nombre d'approbations et l'état de son pipeline, en vert (réussi), rouge (en échec) ou bleu (en cours, en attente, aucun) |
| Documentation      | section du workspace, à l'adresse `/docs` (`?page=`) : à gauche, la recherche (titre et contenu) et le bouton « + » d'une nouvelle page, puis « Filtres supplémentaires », repliés par défaut avec le nombre de filtres actifs (tag, pages archivées), puis l'arborescence ; à droite, la page rendue avec ses tags, son dernier auteur, le bouton « Modifier » et un menu des autres actions (sous-page, historique, télécharger le `.md` de ce qui est affiché — la page ou la version lue —, archiver ou désarchiver, supprimer pour son créateur et l'auteur du workspace). L'édition montre le Markdown et son aperçu côte à côte ; une image s'ajoute par bouton ou par collage. L'historique est un volet des versions (auteur, jeton, date) : en choisir une l'affiche à la place de la page. Rechargée quand une page change (signal `history.created`), sauf pendant une édition. Sur téléphone, la page remplace la liste |
| Agenda             | section du workspace, à l'adresse `/agenda`, chargée à la demande (FullCalendar). À gauche, un panneau (replié derrière un bouton « Options » sur téléphone) : « Nouvel élément », un mois avec les numéros de semaine où un clic sur un jour y amène l'agenda, les membres du workspace courant à cocher pour voir leur emploi du temps (soi-même par défaut, choix gardé par workspace dans le navigateur ; personne de coché : tout), une case par workspace de l'utilisateur (le courant par défaut, choix gardé dans le navigateur), l'interrupteur des planifications. À droite, l'agenda : vues mois, semaine (par défaut sur écran large) et jour (par défaut sur téléphone) ; chaque élément prend la couleur de son workspace. Glisser-déposer pour changer le jour ou l'heure, étirer pour changer la durée ; sélectionner une plage ou « Nouvel élément » ouvre le formulaire (workspace, titre, couleur, journée entière, dates, répétition, participants, lien, description). Une occurrence d'un élément récurrent porte ↻ ; la modifier, la déplacer ou la supprimer demande d'abord la portée (cette occurrence, celle-ci et les suivantes, toutes). Interrupteur « Afficher les planifications » : leurs déclenchements, en pointillés et en lecture seule, mènent aux Automatisations. Rechargé quand un élément du workspace courant change (signal `history.created`) |
| Aide / API         | à l'adresse `/help`, onglets verticaux comme les Paramètres, page choisie dans l'adresse (`?page=`) : accueil, événements envoyés aux appareils (enveloppe du message, variables d'environnement, chaque type avec ses champs et des exemples de payload), lancer le connecteur en service sous Linux (systemd), et sur un serveur sans écran (service détaché des sessions par le lingering, handlers en fichier, délai des handlers, commande root autorisée par sudo, dépannage). Les blocs de code sont colorés (highlight.js, avec seulement les langages utilisés) et ont un bouton pour copier leur contenu brut. Chaque page existe en français et en anglais (`views/docs/fr/`, `views/docs/en/`) ; la version de la langue active est affichée, le français sert de repli |
| Mes workspaces     | modale ouverte par « Gérer mes workspaces » : liste et création à gauche ; à droite, le workspace choisi en trois onglets, chacun enregistré à part : **Général** (nom, couleur, passage sur ce workspace), **Fonctionnalités** (sections, accès MCP, rappel de l'agenda), **Membres** (liste, ajout par e-mail, retrait, départ) |

### Connecter un appareil

Second onglet de la page des appareils :

- trois boutons :
  - **Télécharger le connecteur** : `tower-connector.pyz`, à lancer avec un fichier de
    configuration ;
  - **Télécharger le handler de base** : `tower-handler.pyz` (voir § Handler de base). Une
    modale s'ouvre ensuite avec la commande pour le passer au connecteur et un exemple de
    section `handlers` ;
  - **Créer une configuration** : une sous-modale, avec un nom et des abonnements. Le secret
    n'est affiché qu'une fois ; la sous-modale ne se ferme pas tant que l'utilisateur ne l'a pas
    confirmé, et propose le fichier de configuration ou le **client préconfiguré**
    (`tower-<configuration>-<workspace>.pyz`) ;
- en dessous, les configurations du workspace en deux colonnes. À gauche, leur liste : nom,
  dernière utilisation, et des boutons **Dupliquer** et **Révoquer**. À droite, la configuration
  choisie (la première par défaut) : son nom, son `client_id`, sa dernière utilisation, un
  bouton **Nouveau secret**, ses abonnements et ses handlers, modifiables sur place, chacun
  enregistré à part. Sur un écran étroit, le détail passe sous la liste.

## 6. Modèle de données

Conventions Cube : tables au singulier (le modèle généré porte le nom de la table) et clés
étrangères nommées d'après la table visée (`task.workspace`, pas `workspace_id`).

| Table             | Contenu principal                                                         |
|-------------------|---------------------------------------------------------------------------|
| `user`            | `email`, `password_hash`, `created_at`, `disabled_at`                     |
| `workspace`       | `name`, `accent_color`, `author` (utilisateur créateur), `sections` (JSON, `null` : toutes), `agenda_reminder_minutes` |
| `workspace_member` | `workspace`, `user`                                                      |
| `task`            | voir § Tâches et sprints                                                  |
| `sprint`          | `workspace`, `name`, `state`, `opened_at`, `closed_at`, `created_at`      |
| `sprint_link`     | `sprint`, `label`, `url`, `created_at`                                    |
| `history`         | `workspace`, `type`, `origin`, `user`, `task`, `details`, `created_at`    |
| `event_delivery`  | `history`, `access_key`, `connection_uuid`, `hostname`, `payload`, `status`, `exit_code`, `response`, `responded_at` |
| `access_key`      | configurations : `workspace`, `created_by`, `name`, `client_id`, `secret_hash`, `listen`, `created_at`, `last_used_at`, `revoked_at` |
| `quick_action`    | `workspace`, `name`, `type`, `payload`, `created_at`                      |
| `webhook`         | `workspace`, `name`, `uuid`, `secured`, `secret` (chiffré, nul si non sécurisé), `events` (JSON), `enabled`, `created_at`, `last_called_at` |
| `webhook_call`    | `webhook`, `status`, `ip`, `user_agent`, `payload`, `created_at`          |
| `push_subscription` | `workspace`, `user`, `endpoint` (unique avec `workspace`), `p256dh`, `auth`, `content_encoding`, `user_agent`, `created_at`, `last_success_at` |
| `notification_rule` | `workspace`, `user`, `name`, `listen` (JSON), `title` et `body` (modèles, nuls : texte par défaut), `enabled`, `created_at` |
| `scheduled_event` | `workspace`, `name`, `type`, `payload`, `cron`, `run_at` (ponctuelle seulement), `enabled`, `last_run_at`, `created_at` |
| `integration`     | `workspace`, `type`, `settings` (JSON), `credentials` (chiffré), `last_synced_at`, `last_error` |
| `pull_request`    | `workspace`, `integration`, `external_id`, `repository`, branches source et cible, `source_commit`, `url`, `title`, `author`, `state`, `draft`, `comment_count`, `pipeline_status`, `synced_at` |
| `pull_request_approvals` | `pull_request`, `external_user_id`, `display_name`, `status`        |
| `doc_page`        | `workspace`, `parent`, `title`, `content`, `tags` (JSON), `version`, `archived_at`, `created_by`, `updated_by`, `created_at`, `updated_at` |
| `doc_page_version` | `page`, `version` (unique avec `page`), `title`, `content`, `tags`, `user`, `token` (nom du jeton d'agent), `created_at` |
| `doc_image`       | `workspace`, `name`, `type`, `size`, `file`, `user`, `created_at`          |
| `agenda_item`     | `workspace`, `title`, `description`, `starts_at`, `ends_at` (exclusive), `all_day`, `link`, `color`, `recurrence`, `recurrence_interval`, `recurrence_weekdays`, `recurrence_until`, `created_by`, `created_at`, `updated_at` |
| `agenda_item_exception` | `item`, `occurs_on` (unique ensemble) : jour d'une occurrence retirée ou détachée |
| `agenda_item_participant` | `item`, `user` (unique ensemble)                                  |
| `jira_issue`      | `workspace`, `integration`, `external_id`, `issue_key`, `summary`, `url`, `project`, `issue_type`, `priority`, `status_id`, `status`, `status_category` (`todo`, `in_progress`, `done`), `sprint_id`, `sprint_name`, `sprint_state`, `assignee_id`, `assignee_name`, `assignee_avatar`, `parent` (ticket parent d'une sous-tâche), `tracked`, `synced_at` |

**(prévu)** `job`, `device_connection`, `agent_run`, `agent_memory`.

## 7. API

- **API utilisateur** (consommée par le front Vue) : inscription et connexion, workspaces,
  tâches et sprints, configurations, appareils, événements, quick-actions et planifications, intégrations,
  merge requests, historique. Décrite en OpenAPI (`php do make:openapi`), client front généré
  (`npm run api:generate`). Chaque requête est limitée au workspace courant de
  l'utilisateur, sauf celles de l'agenda, qui atteignent tous ses workspaces (voir § Agenda).
- **API appareil** : la connexion WebSocket `/devices`, authentifiée par la configuration.
- **Webhooks** : `POST /api/hooks/<uuid>`, authentifié par le secret du webhook, ou par son
  adresse seule pour un webhook non sécurisé.
- **Serveur MCP** : `/mcp`, authentifié par un jeton d'agent, voir ci-dessous.

### Serveur MCP

Un agent (Claude Code, Claude Desktop, tout client MCP) se branche sur un workspace avec une
adresse et un jeton. Le protocole est porté par le SDK PHP officiel (`mcp/sdk`), en Streamable
HTTP **sans session** : chaque requête est autonome, php-fpm répond en JSON et ferme.

- **Jetons d'agent** (table `api_token`) : créés par un utilisateur dans Paramètres → Agents IA,
  liés à **un** workspace, en lecture ou en lecture et écriture, avec une expiration facultative.
  La valeur (`tower_…`) n'est affichée qu'à la création ; seul son SHA-256 est gardé. Révoquer
  supprime la ligne. Un jeton cesse de marcher si son compte est désactivé ou si son
  utilisateur quitte le workspace.
- **Accès du workspace** (`workspace.mcp_access`) : `blocked`, `read` (par défaut) ou `write`,
  réglé dans la gestion des workspaces. Le droit effectif d'un jeton est le plus restrictif des
  deux ; un workspace bloqué répond `403` et refuse la création de jetons.
- **Les outils sont des routes de l'API** marquées `#[McpTool('nom', write: …)]`. Leur schéma
  d'entrée vient des slugs du chemin et des règles `Param` du `Request`, leur description de
  `#[Endpoint]`, comme pour l'OpenAPI. Un appel est exécuté par la route elle-même, en interne,
  sous l'identité du jeton (`ApiTokenIdentity` : authentification et session en mémoire,
  workspace du jeton) : mêmes validations, mêmes `ModelResource`, mêmes lignes d'historique.
  Un outil d'écriture n'est pas listé pour un jeton qui ne peut pas écrire, et sa description
  commence par `[write]`. Ajouter un outil, c'est poser l'attribut sur une route. Une route qui
  répond autre chose que du JSON donne une image (`image/*`), un texte (`text/*`) ou un fichier
  joint au client MCP : c'est ainsi que `get_jira_attachment` montre une maquette à l'agent.
- **Historique** : ce que fait un jeton est écrit avec l'`origin` `mcp` et le nom du jeton dans
  `details.token`, au nom de son utilisateur.
- Hors des outils : tout ce qui touche aux secrets et aux accès, et les suppressions.

## 8. Feuille de route

1. ~~Comptes, workspaces, tâches, historique, API utilisateur, front avec navbar.~~
2. ~~WebSocket, connecteur `.pyz`, configurations, appareils connectés, événements et
   quick-actions, abonnements, handler et réponses.~~
3. Intégrations : ~~Bitbucket~~, ~~Jira et tickets transformables en tâches~~, GitLab, GitHub,
   Figma (un agent va chercher seul un morceau de maquette, voir `ROADMAP/MCP.md`).
4. Jobs vers un appareil précis.
5. Agent IA : abstraction, driver Claude, outils, déclenchement par historique, budget.
6. ~~Notifications push.~~

## 9. Décisions

### Prises

**Abonnements portés par la configuration, réglés dans le front.** Plutôt que des capacités
`listen.*` déclarées par le connecteur. Tower filtre à l'envoi, et un changement s'applique
sans toucher à l'appareil.

**Handlers locaux au connecteur.** Une commande unique (`--handler`), ou des motifs associés à
des commandes, dans un fichier ou dans la configuration elle-même pour un setup en un seul
fichier. Plutôt que des plugins Python : le connecteur reste un transport générique. Les
commandes sont exécutées sans shell, et Tower ne les envoie jamais à l'exécution : le prendre
en main ne permet pas d'exécuter autre chose que ce que l'appareil a accepté.

**Livraison écrite avant l'envoi, réponse asynchrone.** L'identifiant de la livraison voyage
avec l'événement ; la réponse peut arriver après une reconnexion et n'est acceptée que de la
même configuration.

**Statuts de tâche indépendants des tickets.** Le ticket suit la demande, la tâche suit le
travail ; les lier obligerait à mapper chaque workflow, et un ticket peut rester en recette
longtemps après que le travail est fini. Le statut du ticket est seulement affiché.

**Sprints sans durée.** Ouverts et clôturés à la main ; la clôture ne déplace rien.

**Bitbucket : merge requests de l'auteur.** L'endpoint par utilisateur évite de configurer une
liste de dépôts. Les merge requests des collègues, y compris celles à relire, ne sont pas
suivies.

**Transport : WebSocket, connecteur livré en `.pyz`.** Un seul canal bidirectionnel pour les
événements et leurs réponses. La dépendance `websockets` est embarquée dans le zipapp, donc
rien à installer sur l'appareil. Les coupures sont gérées par la reconnexion avec backoff.
Alternatives écartées : SSE + POST (deux canaux à synchroniser) et long-polling (latence,
requêtes à vide).

**Jira : tout un board, avec ses colonnes.** Les étapes d'un kanban Jira (leur ordre, les
statuts de chaque colonne) sont définies par un board, pas par les tickets : suivre tous les
tickets assignés, tous projets confondus, ne donnait que les trois catégories de statut, et une
étape sans ticket n'avait pas de colonne. Le prix : un board par profil à configurer, et les tickets
qui ne sont sur aucun board ne sont pas suivis. Plusieurs profils couvrent plusieurs équipes ou
projets, et leur filtre JQL restreint un board trop large, sans perdre ses colonnes ni le sprint
que donne l'API Jira Software ; une configuration par JQL seule aurait dû recréer les deux. Tout le board est affiché, pour voir qui fait
quoi, mais seuls les tickets de l'utilisateur émettent des événements : l'activité de l'équipe
noierait les appareils et l'historique.

**Événements d'intégration précis.** Un type par changement (`pr.pipeline.failed`,
`jira.issue.status_changed`…), avec l'avant et l'après, plutôt qu'un `changed` qui liste des
champs : un appareil s'abonne exactement à ce qui l'intéresse, et un handler agit sans
interpréter un diff. Les anciens types génériques sont supprimés, pas gardés en parallèle :
aucun appareil ne s'y abonnait encore. Le rythme reste de 5 minutes ; les commentaires Jira ne
sont pas suivis, pour ne pas alourdir chaque passage.

**Intégrations par polling et diff.** Tower interroge les outils externes plutôt que de
recevoir leurs webhooks : pas d'exposition à des appels entrants, et un seul mécanisme pour
tous les outils. Les webhooks de Tower sont une entrée générique, définie par l'utilisateur, qui
ne fait que lancer des événements : ils ne remplacent pas ce polling.

### Ouvertes

- **Jobs** : envoi à un appareil précis, routage, politique d'émission pour l'agent, gardes
  côté appareil (`deny`, `ask`, `auto`), et signature HMAC des messages. À rapprocher des
  configurations et du handler actuels.
- Suivre aussi, sur Bitbucket, les merge requests dont on est reviewer.
- Valeurs par défaut du regroupement des runs de l'agent (période sans événement, délai
  maximal).
