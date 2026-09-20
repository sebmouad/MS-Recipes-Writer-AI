# MS Recipes Writer AI

Plugin WordPress en construction pour la génération éditoriale culinaire orchestrée.

## État actuel

La version `0.2.47` est un socle installable : file persistante, pipeline de
génération, contrôle qualité déterministe, budgets, images et écrans
d’administration. Le détail des fonctionnalités livrées se trouve dans
l’historique des versions ci-dessous.

Le plugin est en cours de refonte éditoriale et budgétaire :

- [`docs/PLAN.md`](docs/PLAN.md) — ce qui est construit, en clair.
- [`docs/BUILD-CHECKLIST.md`](docs/BUILD-CHECKLIST.md) — les tâches à réaliser,
  leurs tests et leur critère d’achèvement.
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — le code tel qu’il existe.
- [`docs/TESTING.md`](docs/TESTING.md) — suite hors ligne et tests réels.

Les coûts affichés sont des estimations calculées avec le catalogue configuré,
non une facture fournisseur. `completed` signifie que le traitement est terminé,
jamais qu’un texte est validé éditorialement.

## Version 0.2.47

- Moteur de coût dérivé (`MSRWA_Cost`) : chaque étape est estimée en tokens puis tarifée au prix catalogue du modèle qu’elle utiliserait. Plus aucune constante forfaitaire.
- Estimation en quatre postes — Article, Image mise en avant, Image Facebook, Autres — avec un minimum (tout passe du premier coup) et un maximum (toutes les corrections sont consommées) par poste et par recette.
- Les API d’image facturant l’image produite, le catalogue porte désormais le nombre de tokens générés par taille et par qualité, modifiable par l’administrateur, multiplié par le tarif de sortie du modèle. Une combinaison inconnue est signalée comme inconnue, jamais comme gratuite.
- La taille d’image estimée suit le ratio configuré, via la même table que la génération.

## Version 0.2.46

- Sécurité : toutes les réponses de l’API du plugin sont marquées non stockables et privées. Un cache de page traite une requête authentifiée par mot de passe d’application comme une requête visiteur et pouvait servir la réponse à tout le monde ; constaté en test réel sur un hébergement LiteSpeed qui servait le catalogue en cache à un appelant anonyme.
- Couche de tests réels : exécution depuis un poste distant via l’API REST et un mot de passe d’application, sans accès shell. `php tests/real/run.php` vérifie les prérequis, puis contrôle sur le site en ligne que le plugin est actif, que ses routes répondent, que le catalogue est tarifé et qu’aucune route n’est ouverte au public.

## Version 0.2.45

- Recherche externe de secours supprimée.
- Relecture IA du contenu et du réalisme des deux images, avec verdicts textuels distincts.
- Objectifs de longueur contrôlés séparément et conservés pour les corrections ; minimum par défaut de 2 000 mots, maximum de 2 400 mots, configurables. Nouvelle vérification à la création du brouillon.
- Routage automatique limité aux fournisseurs connectés et aux capacités prises en charge.

## Version 0.2.44

- Configuration dédiée à la pagination en deux pages et aux choix indépendants des images principale et Facebook.
- Choix conservés dans les données de chaque nouveau job ; étapes de génération et de contrôle ignorées pour les images désactivées.
- Facebook sans image principale utilise la recette comme base de génération.

## Version 0.2.43

- Tests de régression sur la planification : un lot continue de se vider au-delà de la limite de simultanéité, un bail expiré libère son créneau, et un job sans créneau est replanifié. La correction 0.2.42 est désormais protégée.

## Version 0.2.42

- Correction majeure de la file : un lot plus grand que la limite de traitements simultanés s’arrêtait après la première vague, les jobs restants n’étant jamais replanifiés. Les créneaux libérés sont désormais repris à chaque fin de job, et un worker sans créneau replanifie son job au lieu de l’abandonner.
- Un bail de worker expiré ne bloque plus un créneau jusqu’au nettoyage quotidien.

## Version 0.2.41

- Actions par job directement dans la liste : relance, annulation et confirmation d’association, avec les mêmes garde-fous que le détail du lot.
- Avertissement de file d’attente sur l’écran de création lorsque des traitements sont interrompus ou que la planification WordPress ne tourne plus ; détail réservé aux administrateurs.
- Statistiques alignées sur le verdict de livraison : taux de contrôles réussis par article, distinct du taux de conformité structurelle, et score moyen lu sur le verdict enregistré.
- Suite de tests hors ligne outillée : `php tests/run.php` (lint + tests), harnais partagé `tests/bootstrap.php`, double `$wpdb` enregistreur, intégration continue PHP 7.4/8.1/8.3.
- Documentation de reprise dans le dépôt : `CLAUDE.md`, `docs/ARCHITECTURE.md`, `docs/ROADMAP.md`, `docs/TESTING.md`.

## Version 0.2.40

- Liste **Articles** complète : pagination, recherche par titre ou numéro de job, filtres publication (brouillon, publié, en attente, planifié, privé, corbeille, brouillon supprimé), qualité, état, période, tri et nombre par page.
- Liste **Jobs** complète : mêmes filtres plus l’étape du pipeline, et un dépliant par ligne réunissant diagnostic technique, erreur, article lié, modèles retenus, corrections par élément, appels, tokens, coûts, fenêtres d’exécution et propriétaire.
- Cloisonnement par éditeur appliqué côté requête : un paramètre d’auteur forgé est ignoré pour qui ne possède pas `msrwa_view_all`. Le filtre Auteur n’est proposé qu’aux administrateurs.
- Verdict qualité persisté sur le job (`quality_score`, `quality_passed`, `quality_checked_at`), écrit à la création du brouillon et rétro-rempli une seule fois à la migration.
- Corrections : le total d’un lot correspond désormais aux jobs réellement créés, un lot sans job est signalé en erreur au lieu de rester bloqué ; la reprise de données structurées ne se rejoue plus à chaque mise à jour ; les écrans de diagnostic bornent leurs requêtes.
- Interface : barre de filtres et pagination dédiées, pastilles de statut de publication, libellés d’état en français, onglet actif annoncé aux lecteurs d’écran.

## Version 0.2.39

- Qualité rattachée à l’article produit : un job sans article n’affiche plus de verdict, et la moyenne d’un lot porte sur ses articles.
- Page de création en deux onglets : liste des articles par défaut, avec ouverture du brouillon et détail du job, puis onglet Jobs pour les jobs et les lots.
- Statistiques qualité calculées sur les articles produits : nombre d’articles, score de structure moyen, conformité, mots moyens et articles sous coût cible.

## Version 0.2.38

- Liens internes contextuels dans les paragraphes uniquement : suppression de la section automatique de recommandations et de son réglage de titre.
- Consignes d’ancres configurables ; conservation du texte et des liens existants, sans lien imbriqué ni cible externe.

## Version 0.2.37

- État simplifié (`encours`, `completed`, `canceled`, `error`) distinct de la qualité (verdict et score de structure en %). `completed` indique la fin du traitement, pas une validation éditoriale. Les pauses et actions requises restent signalées ; une réponse fournisseur incertaine ne vaut pas réussite.
- Sauvegarde du contenu disponible en brouillon pour les jobs à vérifier, échoués, suspendus pour budget ou à résultat fournisseur incertain ; aucune publication automatique.
- Rapport privé de relecture dans le détail du job et l’éditeur : score de structure, observations éditoriales et images manquantes. Un score élevé ne vaut pas validation culinaire.
- Reprise sans doublon et protection des articles qui ne sont plus des brouillons.
- Division en deux pages activable, avec respect des limites HTML ; maintien du contenu non divisé lorsqu’un brouillon partiel est trop court.
- Corrections bornées par élément, préservation des images remplacées et diagnostics API sans secrets ni données binaires.
- Requêtes de recherche bornées et comptabilisées ; suspension après un résultat texte incertain pour éviter une relance payante automatique.
- Conservation du collage Facebook complet avec ajustement configurable et mesure des durées des appels images.

Les coûts affichés sont des estimations calculées avec le catalogue configuré, non une facture fournisseur. Un appel interrompu peut avoir été facturé. La validation éditoriale et les tests complets des fournisseurs restent nécessaires avant utilisation en production.

## Installation locale

1. Installer le dossier dans `wp-content/plugins/ms-recipes-writer-ai/`.
2. Activer **MS Recipes Writer AI**.
3. Ouvrir **MS Recipes Writer → Configuration**.
4. Configurer prompts, modèles, tarifs, budgets opérationnels et clés serveur. Une clé seule ne déclenche aucun job.
5. Utiliser **Créer des Articles/Images** avec une recette ou un brief, puis ajouter des références visuelles si nécessaire.

Les tables sont préfixées par la base WordPress et sont créées à l’activation. La désactivation retire uniquement la planification du plugin ; elle ne supprime pas les données ni les médias.

## Documentation de conception

Le plan complet et les critères d’acceptation se trouvent dans [`../MS-Recipes-Writer-AI-PLAN-FINAL.md`](../MS-Recipes-Writer-AI-PLAN-FINAL.md).
