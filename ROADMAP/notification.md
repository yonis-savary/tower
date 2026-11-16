# Notifications push

Recevoir sur son téléphone, ou sur le bureau, une notification native quand un évènement
choisi se produit dans un workspace, sans appareil connecté ni application tierce : Tower
devient une PWA et envoie lui-même les notifications par Web Push.

Statut : **non commencé**. Ce document fixe le périmètre décidé ; les points marqués
**(à trancher)** restent ouverts.

## Périmètre

### Abonnement d'un navigateur

- Un utilisateur active les notifications **sur chaque navigateur** où il les veut :
  téléphone, ordinateur… Chaque activation crée un **abonnement**, qui lui appartient.
- L'activation se fait sur un clic (exigence des navigateurs pour demander la
  permission), avec un état clair : non supporté, refusé, désactivé, activé.
- La liste de ses abonnements montre le navigateur et la date de la dernière notification
  reçue ; chacun peut être supprimé, et un bouton envoie une notification de test.
- Sur iOS et iPadOS (16.4 et plus), le Web Push ne fonctionne que dans la PWA **ajoutée à
  l'écran d'accueil** : l'écran l'explique quand il détecte Safari hors PWA.

### Règles

- Une règle appartient à un **utilisateur dans un workspace** : chaque membre choisit ce
  qu'il reçoit, personne ne choisit pour les autres.
- Une règle est une liste de motifs d'évènements, avec la même syntaxe que l'écoute des
  configurations d'appareil (`task.*`, `pr.pipeline.failed`, `*` pour tout), 20 au plus.
- Une règle envoie à **tous** les abonnements de son utilisateur.
- **(à trancher)** une règle par utilisateur et par workspace (une simple liste de
  motifs), ou plusieurs règles nommées, chacune avec son texte de notification.

### Contenu d'une notification

- Titre : le nom du workspace et le type de l'évènement. Corps : `payload.title` s'il
  existe, sinon un résumé du payload, tronqué.
- **(à trancher)** un gabarit par règle avec les variables du connecteur
  (`{event}`, `{payload.title}`…), ou ce format fixe dans un premier temps.
- Un clic sur la notification ouvre Tower, ou ramène l'onglet déjà ouvert, sur la ligne
  d'historique de l'évènement.
- Les notifications d'un même type d'évènement se remplacent (`tag`) plutôt que de
  s'empiler. **(à trancher)** par type, ou par objet (une même tâche, une même merge
  request).

### Intégration au reste de Tower

- Un onglet **Notifications** dans la page Automation, pour les règles du workspace
  courant, et l'activation du navigateur courant.
- Pas de section dans `Workspace.sections` : chaque membre décide pour lui, il n'y a rien
  à masquer au niveau du workspace.
- Les textes en anglais et en français, et une page dans la documentation in-app
  (activation, iOS, motifs).

## Data-flow

```
évènement (utilisateur, webhook, planification, intégration, tâche)
  → DevicesChannel::sendEvent()               passage obligé de tous les évènements
      → broadcast vers les appareils          inchangé
      → PushQueue::queue(workspace, history)  nouveau, ne bloque pas l'appelant
          worker push (cube:queue)
            → règles du workspace dont un motif correspond (AccessKeyService::listens)
            → abonnements de leurs utilisateurs, dédoublonnés
            → POST signé VAPID vers le service push du navigateur (FCM, APNs, Mozilla)
                → service worker du navigateur → showNotification()
```

- Le point d'accroche est `DevicesChannel::sendEvent()`, que `EventService`,
  `TaskService` et `IntegrationEvents` appellent tous. L'appel à la file peut y être
  ajouté, ou ces trois appels peuvent passer par un service commun qui fait les deux :
  le second est plus propre, le premier plus petit.
- L'envoi se fait dans une **Queue** : un service push lent ou en panne ne doit retarder
  ni une requête HTTP, ni le tick du scheduler, ni la boucle du serveur websocket.
- Le worker relit les règles à chaque évènement : une modification s'applique tout de
  suite.

## Contraintes techniques

### Web Push

- L'envoi passe par la bibliothèque **`minishlink/web-push`** : elle signe le JWT VAPID
  (ES256) et chiffre le payload pour l'abonnement (RFC 8291, `aes128gcm`, 4 Ko au plus).
  Elle amène `guzzle` et `web-token` ; les extensions PHP qu'elle demande vont dans
  `Docker/Dockerfile.php`.
- Les clés VAPID sont générées une fois ; les changer invalide **tous** les abonnements.
- Réponses du service push : `201` envoyée ; `404` ou `410` l'abonnement n'existe plus
  et il est supprimé ; `413` payload trop gros ; `429` et `5xx` réessayés avec
  `Retry-After`, quelques fois au plus.
- En-têtes `TTL` (une notification vieille d'une heure n'a plus d'intérêt
  **(à trancher)** la durée) et `Urgency: high`.
- Web Push exige HTTPS, sauf sur `localhost` : derrière le reverse proxy en production,
  et tel quel en développement.

### Configuration

- Un `PushConfiguration` dans `cube.php` : clé publique, clé privée et sujet VAPID
  (`mailto:` de l'administrateur, exigé par certains services push), lus dans `.env`
  (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`).
- Une commande `php do app:push-keys` qui affiche une paire de clés à copier dans
  `.env` (`VAPID::createVapidKeys()` de la bibliothèque), sur le modèle de
  `key:generate`.
- Sans clés, la fonctionnalité est masquée dans le front et la file n'est pas alimentée.
- Un service `push` dans `docker-compose.yml` (`cube:queue --queue=App\Queues\PushQueue -l`)
  et son pendant de développement dans `docker-compose.override.yml`.

### Données

- `push_subscription` : utilisateur, endpoint (unique), clés `p256dh` et `auth`,
  user-agent, `created_at`, `last_success_at`.
- `notification_rule` : workspace, utilisateur, motifs (JSON), `enabled`.
- Retirer un membre d'un workspace supprime ses règles de ce workspace ; supprimer un
  utilisateur supprime ses abonnements.

### Front

- Un service worker `Frontend/public/sw.js`, servi à la racine pour couvrir tout le site,
  écrit à la main : pas de plugin Vite ni de Workbox, il ne fait que `push` et
  `notificationclick`, pas de cache hors ligne.
- Le `manifest.json` existe déjà (`display: standalone`) : il suffit pour l'installation
  sur iOS.
- Le navigateur renvoie son abonnement à chaque ouverture de Tower : un endpoint qui a
  changé est mis à jour plutôt que dupliqué.

### Sécurité

- Un abonnement n'est créé, listé ou supprimé que par son utilisateur.
- Le payload passe par le service push du navigateur (Google, Apple, Mozilla) : chiffré,
  il ne leur est pas lisible ; il ne contient de toute façon jamais de secret. Seuls
  l'endpoint et l'heure d'envoi leur sont visibles.
- Une règle n'envoie que les évènements d'un workspace dont l'utilisateur est membre au
  moment de l'envoi, vérifié par le worker.

### Tests

- API des abonnements et des règles, droits compris.
- Correspondance règles / évènement et dédoublonnage des abonnements.
- Traitement d'un élément de la file avec l'envoi remplacé (`asGlobalInstance` sur un
  composant qui enveloppe `WebPush`) : `410` supprime l'abonnement, `429` réessaie.

## Étapes

1. Clés VAPID, `PushConfiguration`, table et API des abonnements, service worker, bouton
   d'activation et notification de test.
2. Table et API des règles, file `PushQueue` et son service Docker, accroche sur les
   évènements.
3. Onglet Notifications dans Automation, liste des abonnements, textes fr/en, page de
   documentation.

## Hors périmètre

- Application native (Android, iOS).
- Notifications par e-mail, ntfy, Gotify, Slack…
- Notifications en dehors des évènements : connexion d'un appareil, appel de webhook
  refusé…
- Plages de silence et regroupement en résumé.
