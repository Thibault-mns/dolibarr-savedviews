# SavedViews — vues enregistrées pour les listes Dolibarr

🇬🇧 [English version](README.md)

SavedViews ajoute une barre d'onglets au-dessus de chaque page de liste Dolibarr. Chaque utilisateur peut enregistrer la vue courante — filtres de recherche, colonnes sélectionnées, mode d'affichage liste/kanban — sous un nom, et la rouvrir plus tard en un clic.

## Fonctionnalités

- Enregistrement en un clic de l'état courant d'une liste : filtres, colonnes visibles, mode d'affichage (liste/kanban)
- Vues **par liste**, **privées par défaut**, partageables avec des groupes d'utilisateurs ou avec tous les utilisateurs (une vue enregistrée sur la liste des factures n'apparaît que sur la liste des factures ; les listes partageant une même URL, comme produits vs services ou clients vs prospects, sont bien séparées)
- Modification d'une vue : renommer, changer le partage, remplacer par les filtres et colonnes actuels
- L'onglet de la vue affichée est mis en évidence, et signalé comme modifié dès qu'un filtre change (mise à jour en un clic)
- **Vue par défaut** personnelle par liste, ouverte automatiquement en arrivant sur la liste
- Fonctionne sur **toutes** les pages de liste Dolibarr, natives ou de modules tiers — aucune configuration par liste
- Compatible multi-entités (les vues sont limitées à l'entité courante)
- Aucun fichier du core modifié ; un simple hook (`printCommonFooter`) + une petite table

## Prérequis

- Dolibarr >= 16.0
- PHP >= 7.0

## Installation

1. Dézippez dans `htdocs/custom/` (ou déployez via *Accueil → Configuration → Modules → Déployer un module externe*)
2. Activez **SavedViews** dans la liste des modules
3. Ouvrez n'importe quelle liste : un bouton `+` apparaît sous le titre de la page — réglez vos filtres/colonnes, cliquez sur `+`, nommez la vue

> **Montée depuis la 1.1.x** : la version 1.2.0 ajoute une colonne, une table et le droit `manage` : désactivez puis réactivez SavedViews.
>
> **Montée depuis la 1.0.x** : la version 1.1.0 introduit des permissions. Elles sont insérées dans `llx_rights_def` à l'activation du module : désactivez puis réactivez SavedViews, puis attribuez les deux droits à vos utilisateurs ou groupes.

## Permissions

| Droit | Autorise |
|-------|----------|
| `read` | Voir les onglets de vues enregistrées et les appliquer |
| `create` | Enregistrer une nouvelle vue, modifier ou supprimer ses propres vues |
| `manage` | Modifier et supprimer les vues partagées par les autres utilisateurs (jamais leurs vues privées) |

`read` et `create` sont accordés par défaut aux nouveaux utilisateurs ; les trois le sont aux administrateurs à l'activation du module. Sans `read`, le module n'injecte strictement rien ; sans `create`, les onglets sont en lecture seule (pas de bouton `+`, ni modification ni suppression dans le menu de l'onglet), mais une vue partagée peut toujours devenir la vue par défaut. Chaque action AJAX revérifie le droit côté serveur.

Les vues partagées sont appliquées par d'autres utilisateurs : leurs données sont donc nettoyées à l'enregistrement puis avant d'être appliquées. Seule une URL relative de la même liste est conservée, sans paramètre `action`, `confirm`, `token`, `massaction` ni `button_*`.

À propos du droit de lecture par liste : une vue ne contient que les paramètres de recherche d'une page que l'utilisateur avait déjà le droit d'ouvrir, et l'appliquer n'est qu'une redirection vers cette URL — le contrôle de permission de la page par Dolibarr reste donc seul maître de ce que l'utilisateur voit réellement.

## Fonctionnement

Le module se branche sur `printCommonFooter` (contexte `all`) et injecte un petit payload JS/CSS. Le JS ne s'active que si la page est une vraie liste (présence du formulaire `searchFormList`). Les vues sont stockées dans `llx_savedviews` (par auteur, par entité, groupes de partage dans `llx_savedviews_usergroup`, clé = chemin de la page + son discriminant `type`). L'application d'une vue redirige vers l'URL enregistrée de la même liste avec tous les paramètres de filtre. La vue par défaut est appliquée par le hook `doActions` avec une redirection, avant le calcul de la liste.

Les échanges AJAX suivent le format de la classe core `JsonResponse` (`result`, `msg`, `newToken`, `data`) ; la classe elle-même est utilisée quand la version de Dolibarr la fournit (>= 24), avec un payload identique en repli sur les versions antérieures.

## Licence

GPL v3+. Voir COPYING.
