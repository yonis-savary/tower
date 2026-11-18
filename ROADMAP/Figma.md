# Intégration Figma

Brancher un workspace sur Figma, comme Jira et Bitbucket : un onglet Figma liste les fichiers
de design, et un agent MCP va chercher seul le fichier (puis le morceau de maquette) dont il a
besoin.

Statut : **mis de côté**. L'API Figma ne permet pas de lister les fichiers à partir d'un jeton
seul (voir [Limite de l'API](#limite-de-lapi)) ; ce document garde les idées et ce qui est
tranché pour la reprise.

## Tranché

- **Activation par workspace** : une section `figma` dans les sections du workspace (onglet de
  la navigation et onglet des paramètres masqués sinon), comme `jira` et `merge_requests`.
- **Jeton du workspace** : un jeton personnel Figma par workspace, chiffré dans `integration`
  comme ceux de Jira et Bitbucket (`type = 'figma'`). Tous les membres voient les fichiers
  auxquels le propriétaire du jeton a accès.
- **Un onglet par équipe** dans la vue Figma : choisir l'équipe va chercher ses projets et
  leurs fichiers.
- **Cache, pour ménager les quotas de Figma** :
  - le contenu d'un fichier est gardé **par version** : clé = fichier + `last_modified`, que
    la liste des fichiers donne déjà, donc sans appel de plus ;
  - ce qui n'a pas de version (projets et fichiers d'une équipe) est gardé **1 h**, et
    invalidable à la main (bouton « Actualiser »).
- **MCP en deux temps** : l'agent voit d'abord la liste des fichiers ; il ne lit le détail
  d'un fichier que s'il en a besoin, toujours à travers le cache.

## Limite de l'API

- Avec un jeton personnel, on liste les projets d'une équipe (`GET /v1/teams/:team_id/projects`)
  puis les fichiers d'un projet (`GET /v1/projects/:project_id/files`), portée `projects:read`,
  palier de débit 2.
- La documentation Figma l'écrit : « It is not possible to programmatically obtain team IDs ».
  L'id d'une équipe ne se lit que dans l'URL de sa page (`figma.com/files/team/<id>/…`).
- Les endpoints v1 des projets sont dépréciés au profit des dossiers v2
  (`GET /v2/teams/:team_id/folders`, `GET /v2/folders/:folder_id/files`, portée
  `folders:read`), qui demandent aussi l'id d'équipe ; des retours de 404 sur la v2 circulent
  encore (forum Figma, août 2026).
- Un outil qui « n'a besoin que d'un jeton » travaille en fait à partir de liens de fichiers
  (`figma.com/design/<file_key>/…`), pas d'une liste.

## Pistes pour la reprise

- **Équipes ajoutées par URL** : un bouton « + équipe » dans l'onglet Figma où l'on colle une
  fois l'URL de la page d'équipe ; le nom de l'onglet vient de la réponse de Figma (le nom de
  l'équipe est dans `GET /v1/teams/:team_id/projects`). Ou bien saisies avec le jeton dans
  Paramètres → Figma.
- **Fichiers ajoutés par lien**, sans équipe : on colle des liens de fichiers, la liste est
  tenue à la main. Ne demande que le jeton.
- **Outils MCP** :
  - `list_figma_files` : les fichiers connus, par équipe et projet ;
  - `get_figma_file` : pages, frames de premier niveau (id, nom, taille) et composants, avec
    un lien par frame. Jamais l'arbre complet, qui pèse souvent plusieurs Mo ;
  - plus tard `get_figma_node` : un frame par son lien ou son id, rendu en PNG avec ses textes
    et ses styles, sur le modèle de `get_jira_attachment` (étape 6 de `MCP.md`).

## Sources

- https://developers.figma.com/docs/rest-api/projects-endpoints/
- https://developers.figma.com/docs/rest-api/folders-endpoints/
- https://forum.figma.com/report-a-problem-6/the-new-folder-rest-apis-doesn-t-work-57025
