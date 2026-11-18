# 🧪 Fiche Mémo Atelier 02 : Résolution de Conflits Merge

> **Référence :** Atelier 02 (Les Bases de Git — Licence MIT)
> **Formateur :** Jean-Marie Renouard — LightPath SARL

## Protocole Rapide de Résolution

```bash
# 1. Inspect conflicted files during MERGING state
git status

# 2. Verify no leftover conflict markers remain after editing
grep -rnE '^(<<<<<<<|=======|>>>>>>>)' .

# 3. Stage resolved files and finalize merge commit
git add pricing.py
git commit -m "merge: resolve pricing.py conflict"
```

## Points de Vigilance

* En cas d'erreur de manipulation pendant la résolution, annulez immédiatement via `git merge --abort`.
* Vérifiez que les tests unitaires passent avant de supprimer les branches fusionnées avec `git branch -d <branche>`.
* Contrôlez le graphe final via `git log --oneline --graph --decorate`.
