# Serveur MCP Tower

Brancher Tower sur un agent (Claude Code, Claude Desktop, tout client MCP) : l'agent lit les
tâches, les tickets Jira, les merge requests et l'historique d'un workspace, et agit dessus :
créer ou déplacer une tâche, lancer un événement vers les appareils, passer un ticket dans une
autre colonne.

Statut : **étapes 1 à 3 en place** (jetons, endpoint `/mcp`, outils de lecture et
d'écriture, accès par workspace, documentation in-app). Ce qui est fait est décrit dans
`ARCHITECTURE.md` § Serveur MCP ; ce document garde l'étude de départ et la suite.

Tranché :

- un jeton = un workspace ; portées `read` / `write` ; plus un niveau par workspace
  (`blocked`, `read` par défaut, `write`), le droit effectif étant le plus restrictif ;
- serveur intégré à Tower, sur le SDK PHP officiel (`mcp/sdk`), en Streamable HTTP sans
  session ;
- un outil est une route de l'API marquée `#[McpTool]`, exécutée en interne sous l'identité du
  jeton (pas une classe par outil) ;
- jeton seul, sans OAuth pour l'instant ; origine `mcp` dans l'historique ;
- le jeton ne vaut que pour `/mcp`, pas pour le reste de l'API : les routes qui prennent un
  identifiant de workspace sortiraient de la borne du jeton.

## Ce que Tower offre déjà

- **Une API JSON complète** sous `/api` : une soixantaine de routes, celles qu'utilise le front.
  Tâches et sprints, tickets Jira (liste, transitions, discussion, création de tâche), merge
  requests et approbations, historique et livraisons, événements et quick-actions,
  planifications, webhooks, règles de notification, workspaces et membres.
- **Un document OpenAPI 3.1** (`php do make:openapi`, dans `Frontend/openapi.json`) : chaque
  route a un `operationId` unique, un résumé (`#[Endpoint]`), ses paramètres et son corps,
  tirés des `Request` et de leurs règles `Param`. C'est déjà presque une liste d'outils MCP.
- **Des webhooks** : une adresse et un secret par webhook, qui lancent des événements sans
  session. C'est le moyen le plus simple pour qu'un programme extérieur agisse.
- **Le connecteur** : un client WebSocket authentifié par une clé d'appareil (`client_id` et
  secret), qui reçoit en direct les événements que sa configuration écoute et peut y répondre,
  et même enchaîner un événement (`next`).
- **Des signaux temps réel** par workspace (`history.created`, `delivery.updated`,
  `webhook.refused`), aujourd'hui réservés au front.

Ce qui manque pour un client non-navigateur :

- **L'authentification passe uniquement par une session** : `POST /api/auth/login` avec
  e-mail et mot de passe pose un cookie de session ; `IsAuthenticated` ne connaît rien d'autre.
  Il n'existe pas de jeton d'API.
- **Le workspace est un état de session** : `PUT /api/workspaces/current` choisit le workspace,
  et toutes les routes travaillent ensuite sur lui. Un appel ne dit jamais lui-même à quel
  workspace il s'adresse.

## Faisable aujourd'hui, sans toucher à Tower

### 1. Un serveur MCP local qui joue le navigateur

Un petit programme à part (Python ou TypeScript, avec le SDK MCP officiel du langage), lancé
par l'agent en **stdio**, qui :

- se connecte avec l'e-mail et le mot de passe d'un compte Tower, garde le cookie de session,
  et se reconnecte quand il expire ;
- choisit le workspace au démarrage (paramètre de lancement), ou expose un outil pour en
  changer ;
- expose une **sélection** d'outils, chacun appelant une route de l'API.

Limites : le mot de passe est en clair dans la configuration de l'agent, l'agent a tous les
droits du compte (rien n'est limité à la lecture), et son activité se confond avec celle de
l'utilisateur dans l'historique. Acceptable pour un usage personnel, pas au-delà.

### 2. Les outils générés depuis l'OpenAPI

Des passerelles génériques savent transformer un document OpenAPI en serveur MCP, une route
devenant un outil. Avec `operationId` et `#[Endpoint]` déjà soignés, cela marche presque tel
quel. Mais 80 outils, c'est trop pour un agent : il faut filtrer (voir § Outils), et ces
passerelles butent sur le même problème d'authentification.

### 3. Recevoir les événements comme un appareil

Le serveur MCP peut ouvrir la connexion WebSocket du connecteur avec une clé d'appareil créée
pour lui : il reçoit les événements écoutés par cette configuration, en direct. Il peut les
garder en mémoire et les exposer comme une ressource (« derniers événements »), ou les pousser
au client MCP sous forme de notification quand celui-ci s'y abonne.

### 4. Seulement agir : les webhooks

Si l'agent n'a besoin que de lancer des événements, un webhook suffit : un outil MCP qui fait
un `POST` signé vers son adresse. Aucune session, et le webhook ne peut lancer que ses propres
événements.

## Ce qu'il faudrait mettre en place dans Tower

### Jetons d'API personnels

Le préalable à tout le reste.

- Un utilisateur crée des jetons dans **Paramètres → Compte** : un nom, une date d'expiration
  facultative. Le jeton n'est affiché qu'une fois, comme le secret d'un webhook ; seule son
  empreinte est stockée. Révocation d'un clic, date de dernière utilisation.
- Une table `api_token` : utilisateur, nom, empreinte, portées, workspace, `created_at`,
  `last_used_at`, `expires_at`, `revoked_at`.
- Un fournisseur d'authentification Cube qui reconnaît `Authorization: Bearer <jeton>`, à côté
  de la session : `IsAuthenticated` accepte l'un ou l'autre, le reste de l'API ne change pas.
- **Portées** : lecture seule, ou lecture et écriture, éventuellement par section (tâches,
  Jira, événements…). **(à trancher)** leur granularité.
- Un jeton est lié à **un workspace** **(à trancher)** : plus simple et plus sûr qu'un jeton
  qui voit tous les workspaces de l'utilisateur. Sinon, chaque appel donne son workspace dans
  un en-tête (`X-Tower-Workspace`), vérifié contre les adhésions.
- Ce que fait un jeton apparaît dans l'historique avec son nom : une origine `token` (ou
  `mcp`), à côté de `user`, `webhook`, `schedule`.
- Le compte désactivé ou le membre retiré d'un workspace perd l'usage de ses jetons.

### Le serveur MCP

Deux places possibles **(à trancher)** :

| | Intégré à Tower (`/mcp`) | Processus à part |
|---|---|---|
| Transport | Streamable HTTP, servi par php-fpm | stdio (local) ou Streamable HTTP |
| Déploiement | rien à installer côté agent : une URL et un jeton | un programme à lancer, ou un service Docker de plus |
| Événements poussés | difficile : php-fpm répond et se ferme, une connexion longue occupe un worker | naturel : il garde la connexion WebSocket du connecteur |
| Code | PHP, dans l'application, au plus près des services | autre langage possible, appelle l'API avec un jeton |

Recommandation : **intégré à Tower** pour les outils et les ressources, en réponses JSON
simples (le transport Streamable HTTP permet de ne pas ouvrir de flux), et repousser les
notifications poussées à plus tard. Un client distant obtient alors Tower avec une URL et un
jeton, sans rien installer. Si les événements en direct deviennent importants, un service
Docker `mcp` sur l'image de php-fpm, comme `websocket`, pourra porter le flux.

Points techniques :

- Le protocole est du JSON-RPC : `initialize`, `tools/list`, `tools/call`, `resources/list`,
  `resources/read`. Un SDK MCP pour PHP existe **(à vérifier)** sa maturité et sa licence avant
  d'en faire une dépendance ; à défaut, ces quelques méthodes s'écrivent en un contrôleur.
- **Une description, plusieurs usages** : le schéma d'entrée de chaque outil se déduit des
  règles `Param` de son `Request`, comme le générateur OpenAPI le fait déjà. Un outil est une
  classe qui déclare son nom, sa description, son `Request` et le service qu'elle appelle ; les
  outils sont trouvés par découverte (`Bunch::fromImplements`), sans liste à tenir.
- **Authentification d'un client distant** : la spécification MCP prévoit OAuth 2.1 pour le
  transport HTTP. Un jeton dans l'en-tête `Authorization`, accepté par la plupart des clients,
  suffit pour commencer **(à trancher)** si OAuth vaut son coût ensuite.
- Les appels passent par les mêmes services que l'API : mêmes validations, mêmes droits,
  mêmes lignes d'historique, mêmes signaux pour le front et le circuit.

### Outils

Une première sélection, courte : un agent choisit mieux parmi quinze outils que parmi quatre
vingts.

| Outil | Route | Effet |
|---|---|---|
| `list_tasks` | `GET /api/tasks` | lecture |
| `create_task` | `POST /api/tasks` | écriture |
| `move_task` | `PUT /api/tasks/{task}/move` | écriture |
| `update_task` | `PUT /api/tasks/{task}` | écriture |
| `list_sprints` | `GET /api/sprints` | lecture |
| `list_jira_profiles` | `GET /api/jira/profiles` | lecture |
| `list_jira_issues` | `GET /api/jira/issues` | lecture |
| `list_jira_transitions` | `GET /api/jira/issues/{issue}/transitions` | lecture |
| `transition_jira_issue` | `POST /api/jira/issues/{issue}/transitions` | écriture, vers Jira |
| `create_task_from_jira_issue` | `POST /api/jira/issues/{issue}/task` | écriture |
| `list_pull_requests` | `GET /api/pull-requests` | lecture |
| `list_history` | `GET /api/history` | lecture |
| `list_deliveries` | `GET /api/history/{line}/deliveries` | lecture |
| `trigger_event` | `POST /api/events` | écriture, vers les appareils |
| `list_quick_actions` / `trigger_quick_action` | `/api/quick-actions` | lecture / écriture |
| `list_schedules` | `GET /api/scheduled-events` | lecture |
| `suggest_event_types` | `GET /api/events/suggestions` | lecture |

Hors de la première version : tout ce qui touche aux secrets et aux accès (clés d'appareil,
secrets de webhook, identifiants des intégrations, membres du workspace), et les suppressions.
Un outil d'écriture le dit dans sa description, pour que le client demande confirmation.

Les réponses des outils passent par les `ModelResource` existantes : jamais de modèle brut.

### Ressources

Des lectures que l'agent peut joindre à son contexte sans appeler d'outil :

- `tower://workspace` : le workspace, ses sections, ses intégrations ;
- `tower://board` : les sprints actifs et leurs tâches par colonne ;
- `tower://jira/mine` : les tickets de l'utilisateur ;
- `tower://pull-requests` : les merge requests ouvertes, avec pipeline et approbations ;
- `tower://history/recent` : les derniers événements.

Plus tard, avec un flux : l'abonnement à une ressource, notifié par les signaux temps réel.

### Tests

- Jetons : création, empreinte seule stockée, expiration, révocation, portées, workspace lié,
  compte désactivé.
- `tools/list` : les schémas d'entrée suivent les règles des `Request`.
- `tools/call` : un outil de lecture refusé en écriture et inversement, une validation qui
  échoue rend une erreur d'outil lisible, l'historique note l'origine du jeton.

## Étapes

Les étapes 1 à 3 sont faites, sauf l'authentification `Bearer` sur toute l'API (voir plus haut).

1. Jetons d'API personnels : table, écran dans Paramètres, authentification `Bearer`, workspace
   lié, origine dans l'historique. Utile seul : le serveur MCP local du § 1 n'a plus besoin du
   mot de passe.
2. Endpoint `/mcp` : `initialize`, `tools/list`, `tools/call`, avec les outils de lecture.
3. Outils d'écriture, portées, page de documentation in-app (brancher Tower dans Claude Code,
   dans Claude Desktop).
4. Ressources.
5. **(à trancher)** flux d'événements et abonnements, OAuth 2.1.
6. Une intégration **Figma** : un agent va chercher seul un morceau de maquette (un frame par son
   lien ou son nom, rendu en image, avec ses textes et ses styles), sur le modèle de
   `get_jira_attachment` pour l'image. Lien du workspace à un fichier ou à un projet, avec un
   jeton personnel Figma chiffré comme ceux de Jira. Idées et limites dans `Figma.md`.
7. ~~Les connexions MCP dans le circuit de l'accueil (`HomeFlow`)~~ : une puce par jeton du
   workspace, allumée à chaque appel par le signal `mcp.called`.

## Points à trancher

- Granularité des portées d'un jeton.
- Un jeton par workspace, ou un en-tête de workspace par appel.
- Serveur intégré ou processus à part ; SDK PHP ou implémentation maison.
- OAuth 2.1, ou jeton seul.
- Origine dans l'historique : `token` (tous les jetons) ou `mcp` (seulement ce serveur).

## Hors périmètre

- Un agent hébergé par Tower : Tower expose ses données, l'intelligence reste côté client.
- L'administration du serveur par MCP (comptes, inscription, configuration).
- Les prompts MCP, tant que les outils et les ressources ne sont pas en place.
