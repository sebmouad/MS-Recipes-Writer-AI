# MS Recipes Writer AI

Plugin WordPress en construction pour la génération éditoriale culinaire orchestrée.

## État actuel

La version `0.2.43` fournit un socle installable DB-first :

- modes administrateur `Automatique` / `Manuel` ;
- limites initiales de 50 recettes par lot, 4 traitements simultanés et 2 corrections ;
- tables persistantes pour lots, jobs et événements ;
- menu autonome **MS Recipes Writer** en trois pages : Créer des Articles/Images, Statistiques et Configuration ;
- réglages protégés des clés API et prompts éditables ;
- prompt du routeur de modèles également configurable, tandis que les contrôles de capacité, de budget et de schéma restent imposés par le moteur ;
- catalogue de capacités et tarifs vérifiés des modèles OpenAI, Gemini et Claude ;
- préfiltre de routage automatique qualité/coût et validation du schéma canonique ;
- snapshot des réglages et modèles sélectionnés pour chaque lot et chaque job ;
- sélection manuelle distincte par étape (rédaction, relecture, images, recherche) ;
- pipeline persistant association → recherche web → recette canonique → article → relecture, avec deux corrections maximum ;
- suggestions de liens internes depuis les recettes locales publiées, sous forme de chemins relatifs et avec une limite administrable ; insertion sur les expressions pertinentes déjà présentes dans les paragraphes, sans section dédiée ;
- adaptateurs OpenAI Responses et Image API testés localement avec sorties JSON et WebP temporaires ;
- génération de l’image principale puis variante Facebook référencée, validation mécanique des médias et création idempotente d’un brouillon WordPress ;
- ratios, qualité OpenAI et format de sortie administrables pour l’image principale et Facebook, avec recadrage non étiré et cohérence MIME/extension après transformation ;
- vérification des droits actuels du propriétaire avant création du brouillon, puis relecture des métadonnées Recipe Card, SEO, image principale et Facebook écrites en base avant de terminer le job ;
- adaptateurs texte OpenAI, Gemini et Claude, budget par recette/jour/mois avec réserve image et statistiques des appels sans secret ;
- formulaire de création réduit à deux entrées : texte/recette et images de référence par URLs HTTPS ou téléversement local privé ;
- page de création organisée en deux onglets : **Articles** par défaut, avec la qualité mesurée sur chaque article produit, puis **Jobs** pour le traitement complet ;
- listes complètes et paginées, filtrables par recherche, publication, qualité, état, étape, période et tri ; chaque éditeur ne voit que ses propres articles et jobs, les administrateurs disposent en plus d’un filtre Auteur ;
- verdict qualité enregistré sur l’article lui-même, ce qui permet de filtrer et de trier sans recalculer les artefacts ;
- actions de relance, d’annulation et de confirmation d’association accessibles depuis la liste des jobs, et alerte de file d’attente sur l’écran de création ;
- suite de tests hors ligne et intégration continue, avec documentation d’architecture, feuille de route et guide de test versionnés dans le dépôt ;
- vue détaillée protégée par lot : jobs, étapes, erreurs, relecture, appels, coûts et ouverture du brouillon ;
- confirmation explicite et accessible d’une association ambiguë depuis le détail du job, avec reprise contrôlée ;
- réconciliation automatique des statuts de lots (terminé, annulé, à vérifier, attente de budget) et reprise explicite après validation du budget ;
- statistiques détaillées par fonctionnalité, état, événement et éditeur, avec périmètre automatiquement limité pour chaque éditeur ;
- statistiques de coût par brouillon terminé et durée moyenne des jobs terminés, plus export CSV visible du journal d’événements ;
- lots multi-recettes depuis les deux champs existants, via une séparation `---`, avec références visuelles partagées puis association IA par recette ;
- recherche visuelle web administrable : les références publiques sûres sont téléchargées temporairement hors du document root, analysées pour dégager une direction artistique, puis employées comme observations abstraites — jamais copiées ni fournies comme actif à l’image générée ;
- contrôle vision structuré des deux images ; les défauts non résolus sont signalés dans le brouillon pour relecture humaine ;
- jusqu’à deux corrections automatiques par image, déclenchées uniquement après une relecture négative et avec les défauts conservés dans le détail du job ; une correction de l’image principale régénère aussi sa variante Facebook ;
- gate éditorial déterministe avant la relecture IA : contrat qualité autonome configurable, score sur 100, seuils de longueur/structure/recette/SEO et retour automatique en correction ;
- contrat Recipe Card complet (temps, portions, calories estimées, cuisine, difficulté, ingrédients, étapes, équipement, notes, FAQ et mots-clés) avec mapping administrable ;
- budget image séparé pour l’image principale et Facebook, coût de recherche web explicite et usage token image exploité lorsqu’il est fourni par l’API ;
- mapping administrable des métadonnées Theme/Facebook, récupération des workers expirés et conservation de données par défaut lors de la désinstallation ;
- tests de connectivité séparés pour OpenAI, Gemini et Claude depuis les réglages administrateur ;
- génération d’image principale Gemini native lorsque ce fournisseur est choisi ; l’édition Facebook avec référence reste explicitement réservée aux adaptateurs compatibles ;
- instantané des modèles par étape dans chaque job, adaptateurs vision Gemini/Claude/OpenAI et contrôles REST pause/reprise/annulation avec protection contre les écritures d’un worker expiré ;
- réservations budgétaires atomiques expirables par appel, règlement/libération après retour fournisseur et nettoyage des réservations abandonnées ;
- respect effectif des durées de rétention configurées pour les journaux et temporaires, avec protection des jobs encore actifs ou à vérifier ;
- protection des lots mis en pause ou en attente : un worker déjà planifié ne peut plus les relancer sans action explicite ;
- commandes administrateur visibles pour mettre en pause, reprendre ou annuler un lot, avec contrôle de propriété et avertissement sur les appels déjà acceptés ;
- diagnostic de santé de file dans Configuration : moteur, prochain nettoyage, dernier progrès, jobs en attente/en cours/à vérifier et workers expirés, sans modifier le cron serveur ;
- routage automatique agentique borné aux modèles connectés et vérifiés, avec coût journalisé et repli explicite vers le préfiltre déterministe si la réponse du routeur est inexploitable ;
- historique avant/après des corrections éditoriales et provenance compacte (sources, modèles, corrections) conservée avec le brouillon final ;
- association IA structurée pour chaque entrée, seuil de confiance, état « À confirmer » et endpoint sécurisé de confirmation éditeur avant la recherche ;
- recherche de secours JSON configurable, désactivée par défaut, avec validation HTTPS/DNS publique, budget et journalisation séparés ;
- clés API chiffrées au repos avec les sels WordPress lorsque OpenSSL est disponible, migration des anciennes valeurs et recours aux variables d’environnement ;
- synchronisation manuelle des identifiants accessibles OpenAI/Gemini, état daté du catalogue et exclusion des modèles confirmés absents du compte ;
- statistiques REST sur plage de dates avec période précédente pour les comparaisons personnalisées, en plus du raccourci par nombre de jours ;
- exports paginés CSV/JSON des jobs, appels et événements, avec filtrage par période et respect de la visibilité éditeur/admin ;
- téléchargement sécurisé des images de référence vers un stockage privé hors document root, analyse vision budgétée, provenance et nettoyage des temporaires ;
- migration non destructive des réglages ajoutés par les versions successives, sans écraser les personnalisations existantes ;
- REST local pour créer et consulter les lots ;
- file durable avec budgets opérationnels par recette, jour et mois.

Les appels fournisseurs restent déclenchés uniquement par les jobs créés par un éditeur autorisé et soumis aux limites budgétaires. Aucune clé n’est incluse dans le dépôt.

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
