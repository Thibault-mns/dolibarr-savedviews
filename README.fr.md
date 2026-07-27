# SavedViews — vues enregistrées pour les listes Dolibarr

🇬🇧 [English version](README.md)

SavedViews ajoute une barre d'onglets au-dessus de chaque page de liste Dolibarr. Chaque utilisateur peut enregistrer la vue courante — filtres de recherche, colonnes sélectionnées, mode d'affichage liste/kanban — sous un nom, et la rouvrir plus tard en un clic.

## Fonctionnalités

- Enregistrement en un clic de l'état courant d'une liste : filtres, colonnes visibles, mode d'affichage (liste/kanban)
- Vues **par utilisateur** et **par liste** (une vue enregistrée sur la liste des factures n'apparaît que sur la liste des factures ; les listes partageant une même URL, comme produits vs services ou clients vs prospects, sont bien séparées)
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

> **Montée depuis la 1.0.x** : la version 1.1.0 introduit des permissions. Elles sont insérées dans `llx_rights_def` à l'activation du module : désactivez puis réactivez SavedViews, puis attribuez les deux droits à vos utilisateurs ou groupes.

## Permissions

| Droit | Autorise |
|-------|----------|
| `read` | Voir les onglets de vues enregistrées et les appliquer |
| `create` | Enregistrer une nouvelle vue, la renommer, supprimer ses propres vues |

Les deux droits sont accordés par défaut aux nouveaux utilisateurs, ainsi qu'aux administrateurs à l'activation du module. Sans `read`, le module n'injecte strictement rien ; sans `create`, les onglets sont en lecture seule (pas de bouton `+`, pas de croix de suppression). Chaque action AJAX revérifie le droit côté serveur, et une vue ne peut être modifiée ou supprimée que par l'utilisateur à qui elle appartient.

À propos du droit de lecture par liste : une vue ne contient que les paramètres de recherche d'une page que l'utilisateur avait déjà le droit d'ouvrir, et l'appliquer n'est qu'une redirection vers cette URL — le contrôle de permission de la page par Dolibarr reste donc seul maître de ce que l'utilisateur voit réellement.

## Fonctionnement

Le module se branche sur `printCommonFooter` (contexte `all`) et injecte un petit payload JS/CSS. Le JS ne s'active que si la page est une vraie liste (présence du formulaire `searchFormList`). Les vues sont stockées dans `llx_savedviews` (par utilisateur, par entité, clé = chemin de la page + son discriminant `type`). L'application d'une vue redirige vers l'URL enregistrée (même origine uniquement) avec tous les paramètres de filtre.

Les échanges AJAX suivent le format de la classe core `JsonResponse` (`result`, `msg`, `newToken`, `data`) ; la classe elle-même est utilisée quand la version de Dolibarr la fournit (>= 24), avec un payload identique en repli sur les versions antérieures.

## Licence

GPL v3+. Voir COPYING.
