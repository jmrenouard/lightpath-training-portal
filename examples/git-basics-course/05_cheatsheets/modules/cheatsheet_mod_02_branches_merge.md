# 📋 Fiche Mémo Module 02 : Branches, Switch & Fusion

> **Référence :** Module 02 (Les Bases de Git — Licence MIT)
> **Formateur :** Jean-Marie Renouard — LightPath SARL

## Gestion des Branches & Stratégies de Fusion

| Commande | Action Exécutée | Détail Technique |
| :--- | :--- | :--- |
| `git branch -vv` | Liste les branches locales | Indique la branche active `*` et son suivi |
| `git switch -c feat/x` | Crée et active `feat/x` | Remplace `git checkout -b feat/x` |
| `git switch main` | Bascule vers `main` | Déplace `HEAD` et met à jour le Working Tree |
| `git merge feat/x` | Fusionne `feat/x` dans la branche active | Fast-Forward si linéaire, sinon 3-way merge |
| `git merge --no-ff feat/x` | Force un commit de fusion | Conserve l'historique de la branche dans le graphe |
| `git merge --abort` | Annule une fusion en cours | Restaure le dépôt à l'état pré-fusion |
| `git branch -d feat/x` | Supprime une branche fusionnée | Protège contre la perte de commits non fusionnés |

## Anatomie d'un Conflit

```text
<<<<<<< HEAD
# Version present on target branch
=======
# Version coming from merged branch
>>>>>>> feat/x
```
