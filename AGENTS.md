# Règles d'Ingénierie & Workflow Git — LightPath Training Portal

1. **Workflow Git Strict (Issue GitHub → Branche dédiée → Tests → Commit → Push → PR → Merge → Fermeture d'Issue avec commentaire) :**
   - **Création d'Issue obligatoire :** Chaque tâche, évolution ou correction de bug doit obligatoirement faire l'objet d'une **Issue GitHub** préalable (`gh issue create`) sur les deux dépôts (`lightpath-training-portal` et `training_postgresql` lorsque tous deux sont concernés).
   - **Zéro commit direct sur `main` :** Toujours créer une branche thématique dédiée (`feat/...`, `fix/...`, `docs/...`).
   - **Tests à 100 % avant commit :** Exécution obligatoire de `php tests/run_tests.php` (100% de réussite exigée) avant tout commit.
   - **Pull Request & Merge :** Ouvrir une PR ciblant `main` référençant l'Issue, puis fusionner la PR dès validation des tests.
   - **Clôture d'Issue commentée :** Fermer systématiquement l'Issue GitHub avec un commentaire détaillant les correctifs, le numéro de PR fusionnée et les résultats de tests (`gh issue close <num> --comment "..."`) sur les deux dépôts après le merge.
2. **Design & Qualité Studio LightPath SARL :**
   - Palette Obsidian `#0B1120`, Cyan `#00B0F0`, chiffres tabulaires `tabular-nums`, autonomie totale (zéro CDN externe).
   - Branding exclusif LightPath SARL — Formateur : Jean-Marie Renouard.
   - Barres verticales gauche et droite repliables à la demande ; barre verticale de droite sticky (`overflow-x: clip` sur `body`) et sans aucune barre de défilement visible (`scrollbar-width: none`).

