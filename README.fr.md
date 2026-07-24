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

## Fonctionnement

Le module se branche sur `printCommonFooter` (contexte `all`) et injecte un petit payload JS/CSS. Le JS ne s'active que si la page est une vraie liste (présence du formulaire `searchFormList`). Les vues sont stockées dans `llx_savedviews` (par utilisateur, par entité, clé = chemin de la page + son discriminant `type`). L'application d'une vue redirige vers l'URL enregistrée (même origine uniquement) avec tous les paramètres de filtre.

## Licence

GPL v3+. Voir COPYING.
