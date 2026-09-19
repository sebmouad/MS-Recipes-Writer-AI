# MS Recipes Writer AI

Plugin WordPress en construction pour la génération éditoriale culinaire orchestrée.

## État actuel

La version `0.2.16` fournit un socle installable et sans appel payant automatique par défaut :

- modes administrateur `Automatique` / `Manuel` ;
- limites initiales de 50 recettes par lot, 4 traitements simultanés et 2 corrections ;
- tables persistantes pour lots, jobs et événements ;
- écran MS Tools (ou menu autonome si MS Tools est absent) ;
- réglages protégés des clés API et prompts éditables ;
- catalogue de capacités et tarifs vérifiés des modèles OpenAI, Gemini et Claude ;
- préfiltre de routage automatique qualité/coût et validation du schéma canonique ;
- snapshot des réglages et modèles sélectionnés pour chaque lot et chaque job ;
- sélection manuelle distincte par étape (rédaction, relecture, images, recherche) ;
- pipeline persistant association → recherche web → recette canonique → article → relecture, avec deux corrections maximum ;
- suggestions de liens internes depuis les recettes locales publiées, sous forme de chemins relatifs et avec une limite administrable ; insertion de secours dans une section personnalisable lorsque l’article ne contient pas encore les ancres ;
- adaptateurs OpenAI Responses et Image API testés localement avec sorties JSON et WebP temporaires ;
- génération de l’image principale puis variante Facebook référencée, validation mécanique des médias et création idempotente d’un brouillon WordPress ;
- adaptateurs texte OpenAI, Gemini et Claude, budget par recette/jour/mois avec réserve image et statistiques des appels sans secret ;
- entrée de lots avec titres, textes et plusieurs URLs d’images par recette, verrous de workers, retries avec backoff et relance d’un job autorisé ;
- contrôle vision structuré des deux images avant la création du brouillon, avec blocage si un défaut est confirmé ;
- mapping administrable des métadonnées Theme/Facebook, récupération des workers expirés et conservation de données par défaut lors de la désinstallation ;
- tests de connectivité séparés pour OpenAI, Gemini et Claude depuis les réglages administrateur ;
- génération d’image principale Gemini native lorsque ce fournisseur est choisi ; l’édition Facebook avec référence reste explicitement réservée aux adaptateurs compatibles ;
- instantané des modèles par étape dans chaque job, adaptateurs vision Gemini/Claude/OpenAI et contrôles REST pause/reprise/annulation avec protection contre les écritures d’un worker expiré ;
- réservations budgétaires atomiques expirables par appel, règlement/libération après retour fournisseur et nettoyage des réservations abandonnées ;
- commandes administrateur visibles pour mettre en pause, reprendre ou annuler un lot, avec contrôle de propriété et avertissement sur les appels déjà acceptés ;
- routage automatique agentique borné aux modèles connectés et vérifiés, avec coût journalisé et repli explicite vers le préfiltre déterministe si la réponse du routeur est inexploitable ;
- historique avant/après des corrections éditoriales et provenance compacte (sources, modèles, corrections) conservée avec le brouillon final ;
- association IA structurée pour chaque entrée, seuil de confiance, état « À confirmer » et endpoint sécurisé de confirmation éditeur avant la recherche ;
- recherche de secours JSON configurable, désactivée par défaut, avec validation HTTPS/DNS publique, budget et journalisation séparés ;
- clés API chiffrées au repos avec les sels WordPress lorsque OpenSSL est disponible, migration des anciennes valeurs et recours aux variables d’environnement ;
- synchronisation manuelle des identifiants accessibles OpenAI/Gemini, état daté du catalogue et exclusion des modèles confirmés absents du compte ;
- REST local pour créer et consulter les lots ;
- file durable qui place les lots en attente de validation du budget de test.

Le pipeline externe, les appels fournisseurs réels et la création de brouillon WordPress seront ajoutés après validation des contrats et d’un budget de tests payants. Aucune clé n’est incluse dans le dépôt.

## Installation locale

1. Installer le dossier dans `wp-content/plugins/ms-recipes-writer-ai/`.
2. Activer **MS Recipes Writer AI**.
3. Ouvrir **MS Tools → MS Recipes Writer AI → Réglages**.
4. Configurer les prompts (recherche, recette canonique, article, relecture, image principale et collage Facebook) et, si nécessaire, les clés côté serveur. Une clé seule ne déclenche pas d’appel payant.
5. Utiliser l’écran principal pour créer un lot de titres.

Les tables sont préfixées par la base WordPress et sont créées à l’activation. La désactivation retire uniquement la planification du plugin ; elle ne supprime pas les données ni les médias.

## Documentation de conception

Le plan complet et les critères d’acceptation se trouvent dans [`../MS-Recipes-Writer-AI-PLAN-FINAL.md`](../MS-Recipes-Writer-AI-PLAN-FINAL.md).
