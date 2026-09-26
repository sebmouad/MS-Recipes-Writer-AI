# MS Recipes Writer AI

Plugin WordPress de rédaction culinaire assistée. Un rédacteur dépose plusieurs
recettes et plusieurs photographies ; le plugin apparie chaque photo à sa
recette, fait rechercher, écrire, relire, vérifier et illustrer chaque article
par le moteur, puis livre un **brouillon** WordPress — jamais une publication.

## État actuel — 0.28.39

La version `0.28.39` applique les cinq corrections du moteur que l'audit
proposait : le contrôle des images ne refuse plus pour un compte, un plat ou
une découpe, la relecture suit la langue de l'article, les images de
référence sont facturées à leur vrai prix, et une correction ne laisse plus
une phrase orpheline ; la `0.28.38` corrige ce qu'un audit complet a trouvé : reprendre une
recette arrêtée aboutit enfin, un lot ne part plus deux fois, l'estimation
compte l'appel qui écrit le prompt du collage, et le nettoyage retire les
fichiers que plus rien n'ouvre ; la `0.28.37` garde le rapport complet en clair seulement ; la
`0.28.36` habillait le rapport complet aux couleurs de
l'extension, avec un mode sombre ; la `0.28.35` refaisait le rapport complet d'une recette : sections
repliables, dans l'ordre où la recette s'est faite ; la `0.28.34` renommait *Le pass* en *Lots de recettes*, fait
réconcilier l'analyse avec la dépense et enrichit le diagnostic ; la
`0.28.33` harmonisait les mots de tous les écrans et améliore le
réglage des types de lot sur téléphone ; la `0.28.32` rangeait les étapes
dans l'ordre où elles tournent ; la `0.28.31` faisait ouvrir l'article sur sa
première section et annoncer la page 2. Le détail
de chaque version suit, de la plus récente à la plus ancienne.

Vérifié de bout en bout sur un vrai WordPress : un rédacteur dépose un lot de
texte, de photographies ou des deux ; chaque photographie est lue une fois et
associée à sa recette — celle d’un plat absent du texte devient une recette,
celle qu’on ne reconnaît pas attend la décision du rédacteur ; le cron fait
avancer le lot vague par vague, et chaque recette arrive en **brouillon**, en
blocs, avec son article (2 400 mots par défaut), sa recette structurée, ses
métadonnées SEO, son image à la une et son collage Facebook de six panneaux.
Une recette complète coûte environ 0,05 $ avec votre propre collage Facebook,
0,07 $ avec une photographie du plat et 0,09 $ à partir du texte seul, d’après
les estimations comparées aux recettes réellement produites. Les photographies sources restent hors de la médiathèque, dans un
dossier propre à l’extension, avec l’historique complet de chaque tâche.

| Rôle | Ce qu’il voit |
| --- | --- |
| Rédacteur (`msrwa_create` et `upload_files` : auteur, éditeur) | ses lots, l’état de chaque recette en une phrase, le brouillon, les remarques du contrôle final — **aucun montant, aucun modèle** |
| Administrateur (`msrwa_manage`) | tout : coûts, plafonds, modèles, diagnostics, moteur, réglages |

Ce qui fonctionne : lots multi-recettes avec appariement des photos, trois
sorties (article seul, article et image à la une, chaîne complète où le collage
Facebook — le vôtre, ou dessiné d’abord — guide la recette), quatre
langues d’article (français, anglais, espagnol, arabe), estimation avant dépense et refus
gratuit d’un lot qui dépasserait son plafond, plafonds par recette, par jour et
sur trente jours, file suspendable, reprise d’une recette arrêtée sans repayer
ce qui a réussi, lots programmés, export CSV, rétention réglable, vérification
gratuite des clés d’API, JSON-LD Recipe et aperçu de partage Open Graph sur les
articles publiés.

Les coûts affichés sont des estimations calculées avec les tarifs configurés,
jamais une facture. `done` veut dire que le traitement est terminé, jamais qu’un
texte est validé éditorialement.

### Installation

1. Copier le dossier dans `wp-content/plugins/` et activer l’extension.
2. *MS Recipes AI → Réglages* : enregistrer au moins une clé d’API, puis
   **Vérifier les clés**.
3. *Moteur* : choisir quel fournisseur sert chaque étape (OpenAI par défaut).
4. *Lots de recettes*, en haut de la page : coller une ou plusieurs recettes,
   séparées par une ligne `---`, déposer des photographies — ou votre propre
   collage Facebook —, ou les deux.

Le travail avance par le cron de WordPress. Si `DISABLE_WP_CRON` est actif, un
cron serveur doit appeler `wp-cron.php` toutes les cinq minutes.

### Documentation

Ce fichier est le seul document à la racine : il s’adresse au propriétaire du
site. Tout le reste vit dans [`.claude/`](.claude/), avec
[`CLAUDE.md`](CLAUDE.md) comme porte d’entrée pour qui reprend le
développement, humain ou agent.

- [`CLAUDE.md`](CLAUDE.md) — style de code, invariants, et la procédure à
  suivre pour livrer un changement. À lire en premier.
- [`.claude/docs/PLAN.md`](.claude/docs/PLAN.md) — les six jalons, en clair, et
  où ils en sont.
- [`.claude/docs/BUILD-CHECKLIST.md`](.claude/docs/BUILD-CHECKLIST.md) — les
  tâches, leurs tests et leur critère d’achèvement.
- [`.claude/docs/ARCHITECTURE.md`](.claude/docs/ARCHITECTURE.md) — le code tel
  qu’il existe, et les invariants au complet.
- [`.claude/docs/ENGINE.md`](.claude/docs/ENGINE.md) — le contrat du moteur, et
  les changements proposés qui attendent l’accord du propriétaire.
- [`.claude/docs/TESTING.md`](.claude/docs/TESTING.md) — suite hors ligne, tests
  réels, et comment monter un WordPress jetable.
- [`.claude/docs/LAB.md`](.claude/docs/LAB.md) — le laboratoire de prompts
  (`tools/`), ses commandes et ses règles de provenance d’images.
- [`.claude/docs/LAB-RESULTS.md`](.claude/docs/LAB-RESULTS.md) — ce que les
  mesures du laboratoire ont établi, pour ne pas les refaire.

## Version 0.28.39

**Les cinq corrections du moteur, appliquées.**
- **Le contrôle des images refuse moins à tort.** Il lisait les consignes
  données au modèle d'image comme des règles : le nombre d'œufs, le plat de
  service ou une part découpée suffisaient à refuser une recette sur quatre,
  et invitaient à un nouveau dessin payant. Ces consignes lui sont désormais
  données comme contexte ; un compte, un plat ou une découpe ne bloquent plus.
- **La relecture suit la langue de l'article** : un article en anglais ou en
  arabe n'est plus relu pour des accents français.
- **La catégorie de la recette** (plat principal, dessert…) est écrite dans la
  langue de l'article, pour les données structurées et le classement.
- **Les images de référence au vrai prix.** Le modèle d'image facture une
  image reçue plus cher que le texte (8 $ contre 5 $ par million de jetons) :
  environ 0,007 $ par recette étaient comptés trop bas. L'estimation d'une
  recette complète passe à 0,099 $ ; le plafond par recette livré passe de
  0,15 $ à 0,16 $ pour que le pire cas y tienne. Un site qui a déjà enregistré
  son plafond le garde.
- **Une correction ne laisse plus de phrase orpheline** : quand retirer une
  phrase laisserait la suivante commencer par « Leur » ou « Cette » sans rien
  à quoi se rapporter, la phrase est gardée ou laissée au rédacteur.
- Une recette ou un lot introuvable répond désormais « 404 ».

## Version 0.28.38

**Un audit complet, et ce qu'il a corrigé.**
- **Reprendre une recette arrêtée aboutit.** L'échec de la première tentative
  restait inscrit : même une reprise réussie finissait « en échec ».
- **Un lot ne part plus deux fois.** Deux clics sur « Lancer », ou un clic à
  l'heure programmée, créaient chaque recette en double et la payaient deux
  fois. Un lot refusé à son heure (plafond, modèle retiré) le dit sur sa page.
- **Une recette annulée reste annulée**, même si la file est mise en pause
  pendant qu'un ancien rappel du cron la réveille.
- **Un seul nouveau dessin à la fois** par recette : un double clic ne paie
  plus deux images.
- **L'estimation compte l'appel qui écrit le prompt du collage** (environ
  0,002 $ par recette), et la lecture du collage au bon prix : étape par
  étape, elle suit désormais ce que douze recettes réelles ont coûté ; une
  recette complète est estimée à 0,092 $, facturée 0,084 $ au test réel.
- **Moins de stockage.** Chaque vague ne réécrit plus toutes les productions,
  un nouveau dessin ne recopie plus l'article, la recette et le verdict que
  WordPress garde déjà, et le nettoyage horaire retire les dessins qu'un
  brouillon a déjà dans la médiathèque et les dossiers de recettes supprimées :
  91 Mo ramenés à 35 Mo sur le site d'essai.
- **Une recette ou un lot introuvable** s'affiche dans la page de l'extension,
  avec le bouton pour revenir, au lieu de l'écran d'erreur de WordPress. Le
  bouton « Retour au pass » devient « Retour aux lots ».
- Cinq corrections du moteur proposées au propriétaire (ENGINE.md §7, 8 à 12),
  dont le contrôle des images qui refuse à tort une recette sur quatre.

## Version 0.28.37

**Le rapport complet reste clair**, même quand l'ordinateur ou le téléphone
est en mode sombre : le mode sombre ajouté en 0.28.36 est retiré.

## Version 0.28.36

**Le rapport complet aux couleurs de l'extension.**
- Les mêmes couleurs que les écrans : encre ardoise, un seul accent ambré,
  vert, ambre et rouge pour ce qui va, ce qui demande un regard et ce qui
  bloque. Les bruns et les rouges mêlés ont disparu.
- Un mode sombre, qui suit le réglage de l'ordinateur ou du téléphone.
- Titres de section plus nets, chiffres alignés, tableaux encadrés, en-têtes
  de colonnes discrets ; l'article garde sa typographie de lecture.

## Version 0.28.35

**Le rapport complet, repensé.**
- Chaque section se replie, numérotée, et dit sur sa ligne fermée ce qu'elle
  contient : « 3 constat(s) », « 18 ingrédients · 15 étapes », « Conforme ».
- L'ordre suit la fabrication de la recette : résumé, historique, recherche,
  collage Facebook (avant la recette quand il la guide), recette, article,
  image à la une, relecture, contrôle final ; puis les détails techniques,
  repliés.
- L'article se replie page par page, avec le nombre de mots de chacune ; ses
  données SEO sont rangées avec lui.
- Les tableaux tiennent dans la page (le total des appels était coupé) et les
  étapes portent leur nom — « Lecture du collage », « Contrôle final » — au lieu
  de leur clé.
- L'en-tête dit « Recette #81 · terminée », avec l'issue, le temps, le coût
  estimé et le nombre de mots.

## Version 0.28.34

**Lots de recettes.** Le menu et la page *Le pass* s'appellent désormais
*Lots de recettes* : le nouveau lot en haut, les lots en cours dessous.

**Analyse.**
- « Où part l'argent » compte l'appariement des photographies dans les
  autres étapes : la somme du tableau égale la dépense de l'en-tête.
- Les tableaux par étape, par modèle et par contrôle s'empilent sur
  téléphone ; les fournisseurs portent leur nom.

**Diagnostic.**
- Trois contrôles de plus : le traitement d'image (GD, WebP), le style des
  collages, les échecs des dernières 24 heures.
- Un bouton « Vérifier à nouveau », une note quand tout est en ordre, et
  chaque correction à faire mise en évidence.
- La ligne des clés d'API dit clairement qu'une clé enregistrée n'est pas
  forcément valide, et que « Vérifier les clés » la teste sans frais.

**Plus de « — généré » dans la liste des articles** de WordPress : les
brouillons de l'extension s'y affichent comme les autres.

**Pas de doublon avec le thème.** Le JSON-LD Recipe et l'Open Graph ne sont
imprimés que si le thème MS Recipes (ou MS SEO Plus) ne le fait pas : si le
réglage correspondant du thème est coupé, l'extension prend le relais.

## Version 0.28.33

**Les mêmes mots partout.**
- Plus de « run » à l'écran : on parle de recettes partout, dans l'analyse,
  la rétention et les messages d'erreur.
- « Collage Facebook » partout, au lieu d'« image Facebook » par endroits.
- « Contrôle final » partout, au lieu de « jugement final » ou « approbation
  finale » ; « type de lot » au lieu de « profil ».
- L'aide du plafond par recette ne parle plus de nouvelles tentatives, qui
  n'existent plus.
- Analyse : « Où part l'argent » nomme ses postes Article, Image à la une,
  Collage Facebook et Autres étapes ; la colonne « Exéc. » devient « Passages » ;
  l'ancienne vérification des faits porte son nom au lieu de `fact_check`.
- Diagnostic : les fournisseurs sont nommés (OpenAI, Google Gemini,
  Anthropic Claude), plus leurs identifiants.
- Moteur : le collage est décrit comme dessiné avant la recette, l'image à la
  une comme le plat fini du collage.
- En anglais, « lot » partout, plus « batch ».
- Réglages sur téléphone : *Ce que produit un lot* devient une carte par type
  d'utilisateur, avec les trois types nommés, au lieu d'un tableau coupé.

## Version 0.28.32

**Les étapes dans l'ordre où elles tournent.**
- Écrans *Moteur* (le tableau des étapes et le choix des modèles) et
  *Modèles* : recherche, consigne du collage, collage Facebook, lecture du
  collage, recette de référence, rédaction, image à la une, relecture,
  contrôle final. Le collage apparaissait jusqu'ici après l'article.
- La lecture des images s'appelle désormais *Lecture des photographies et
  du collage* : le même modèle lit les photographies des lots et le collage.

## Version 0.28.31

**L'article commence par l'article, et annonce sa page 2.**
- Il ne répète plus son titre en premier intertitre, et aucun paragraphe ni
  intertitre ne commence par une étiquette comme « Introduction : »,
  « Deuxième partie » ou « Second article » : la consigne l'interdit, et
  l'extension les retire du brouillon si elles reviennent.
- La première page se termine par un paragraphe qui annonce la page 2 : la
  consigne le demande à l'article, et si ce paragraphe manque, l'extension
  ajoute « La suite de la recette, « Préparation de la recette étape par
  étape », vous attend à la page 2. », dans la langue de l'article.
- L'écran d'une recette montre les initiales de la personne, dessinées sur
  place : plus d'appel à Gravatar.

## Version 0.28.30

**Le type de lot se règle une fois, par type d'utilisateur.**
- Le formulaire *Nouveau lot de recettes* ne demande plus que les recettes et
  les photographies : plus de choix de ce qu'il faut produire, plus de rappel
  de la langue ni du plafond.
- *Réglages → Ce que produit un lot* : un tableau, une ligne par type
  d'utilisateur — rédacteur, éditeur, administrateur — et une colonne par
  type de lot avec son coût estimé. Chaque lot prend le type de la personne
  qui l'envoie. Un site qui avait fermé des types aux rédacteurs garde, pour
  les rédacteurs et les éditeurs, le plus complet qu'il avait laissé ouvert.
- *Nouveau lot de recettes* dans la barre d'outils de WordPress, en haut de
  chaque écran de l'administration et du site, pour qui peut envoyer un lot.
- L'écran d'une recette dit qui l'a envoyée (avec son avatar) et, s'il
  diffère, l'auteur de l'article ; le lot, son type et sa langue ; quand elle
  a été créée et terminée ; l'état de l'article, ses catégories et sa
  longueur ; ce qui a été envoyé (texte seul ou photographies, votre collage
  ou un collage dessiné en premier) ; et les liens pour le modifier ou le voir.
- L'estimation sous le formulaire tient en une phrase.

## Version 0.28.29

**Le nouveau lot et le pass sur une seule page.**
- *Le pass* s'ouvre sur le formulaire du nouveau lot — recettes et
  photographies côte à côte, les trois profils sur une ligne avec leur coût,
  puis une barre avec l'estimation et le bouton — et montre en dessous ce qui
  est en cours, ce qui attend et ce qui s'est arrêté. L'entrée de menu
  *Nouveau lot* disparaît ; son ancienne adresse ouvre le pass.
- Écran d'une recette : les miniatures ont toutes la même hauteur, entières,
  et sur téléphone le dernier verdict prend toute la ligne au lieu de laisser
  une case vide.
- Moins de fichiers sur le disque : l'extension ne fait plus faire que les
  tailles qu'elle affiche — pour l'image à la une, la vignette, les tailles
  moyennes et celle du thème ; pour le collage, la seule taille moyenne.
- Collage d'abord : un écart entre l'image à la une et le collage se corrige
  toujours sur l'image à la une, et un plat entier face à une part coupée
  n'est plus un écart.
- Nettoyage : une capture d'écran versée par erreur, deux fonctions que plus
  rien n'appelait et 23 traductions de textes disparus.

## Version 0.28.28

**Estimation, cache, icônes et textes remis à jour.**
- L'estimation suit le nouvel ordre : lecture du collage comptée, image à
  la une dessinée avec sa référence, et votre propre collage jamais compté
  comme un dessin. Comparée aux recettes réelles : 0,090 $ estimé pour
  0,085 à 0,095 $ payés (texte seul), 0,070 $ pour 0,067 à 0,070 $ (avec
  photo), 0,047 $ pour 0,042 à 0,049 $ (votre collage).
- Le cache du fournisseur sert enfin l'article et la relecture d'une
  recette à l'autre : leurs consignes étaient envoyées sous une clé
  propre à chaque recette, et rien n'était jamais réutilisé.
- Les icônes des boutons (œil des clés, « Vérifier les clés ») sont
  centrées, y compris avec les boutons de WordPress 7.
- Les descriptions des profils et l'avertissement de coût maximal
  décrivent le fonctionnement actuel : collage d'abord, aucune image
  redessinée d'office.

## Version 0.28.27

**Le collage Facebook d'abord, la recette ensuite.**
- **Votre propre collage** (fait avec ChatGPT, par exemple) : ajoutez-le avec
  les photographies. Il est reconnu à l'appariement et marqué « Votre
  collage Facebook ». Coché, il est publié tel quel avec l'article et devient
  la référence de la recette, de l'article et de l'image à la une ; il n'est
  ni redessiné ni jugé. Décoché, il sert de simple photographie.
- **Sans collage, sur un lot complet** : le collage est dessiné juste après
  la recherche, en toute liberté — herbes, garniture, accompagnement, linge :
  tout ce qu'une personne mettrait chez elle. La recette, l'article et
  l'image à la une suivent ensuite ce qu'il montre.
- **Un collage qui a l'air fait à la maison**, réglé sur vos collages
  ChatGPT : cadrage serré où le plat remplit chaque case, bois clair,
  marbre et torchon à carreaux, lumière douce de fenêtre sans reflet
  orangé, couleurs naturelles (la viande garde sa couleur), une seule
  méthode de cuisson du début à la fin, aucun thermomètre ni chiffre.
- Un verre posé à côté de l'assiette n'est plus compté comme ingrédient.
- Votre collage n'est jamais redessiné : un écart entre les deux images
  se corrige sur l'image à la une.
- Le juge ne refuse plus un collage dessiné d'abord pour le nombre d'œufs
  visibles ou pour une tarte servie sur une assiette plutôt que dans son
  moule : la présentation est celle du collage.
- Votre collage reste visible sur l'écran du lot après publication, et
  l'écran de la recette l'affiche comme « Votre collage », non noté.
- L'avertissement « brouillon modifié » n'apparaît plus quand personne n'a
  touché au texte.
- Dans les deux cas, la recette reprend chaque ingrédient visible sur le
  collage ; les quantités, temps, températures et règles de sécurité
  viennent de la recherche. L'image à la une montre le même plat.
- Les lots « Article et image » et « Article seul » gardent l'ordre
  d'avant.

## Version 0.28.26

**L'article est rangé au moment de sa publication.**
- Quand vous publiez (ou programmez) un article écrit par l'extension qui
  n'est encore que dans la catégorie par défaut, il est rangé dans une ou deux
  des catégories existantes du site, d'après ce qu'il porte : la catégorie et
  la cuisine de la recette, la catégorie suggérée, son titre, ses mots-clés
  et ses étiquettes. « Gratin dauphinois » va dans « Gratins ».
- Utile pour les brouillons écrits avant la 0.28.25 et pour une catégorie
  créée depuis. Une catégorie choisie par l'éditeur n'est jamais remplacée,
  aucune n'est créée, et aucun modèle n'est appelé : la publication reste
  instantanée et gratuite.
- Un mot proche ne suffit pas : « Tarte aux pommes » ne va pas dans
  « Pommes de terre ».

## Version 0.28.25

**Chaque article est rangé dans les catégories du site.**
- L'article inventait ses catégories (« Apéritif », « Cuisine française
  familiale ») et l'extension ne garde que celles qui existent : sur un site
  aux catégories différentes, l'article restait « Non classé ».
- Il reçoit maintenant la liste des catégories du site et en choisit une ou
  deux, recopiées exactement. Mesuré : croquettes → Entrées et Fritures et
  beignets ; gratin de cabillaud → Poissons et fruits de mer et Gratins ;
  rôti Orloff → Viandes et volailles. Même coût.
- Aucune catégorie n'est créée ; si rien ne convient, la catégorie proposée
  par la recette reste une suggestion pour l'éditeur, comme avant.

## Version 0.28.24

**Les recettes en tableau.**
- Le pass, Articles et la page d'un lot partagent un tableau : Recette
  (vignette, titre, numéro, lot, type, langue), Rédacteur, État, Article,
  Créée, Étapes, Durée, Coût, puis Modifier et Prévisualiser en icônes.
- L'état reste lisible d'un coup d'œil : la bande de couleur au début de
  chaque ligne. Les chiffres sont alignés à droite en chiffres tabulaires ;
  les en-têtes ne sont plus en capitales.
- Un rédacteur ne voit ni la colonne Rédacteur, ni Durée, ni Coût.
- Sur téléphone, chaque ligne redevient un bloc : la recette en haut, les
  informations deux par deux, étapes, durée et coût sur une même ligne.

## Version 0.28.23

**La ligne de recette, redessinée.**
- D'un côté ce qu'est la recette : une vignette plus grande, le titre, puis
  l'auteur, le lot, le type, la langue et la date.
- De l'autre où elle en est : l'état, et juste dessous les chiffres qui
  l'expliquent — étapes, durée, coût — alignés d'une ligne à l'autre ; puis
  les boutons Modifier et Prévisualiser.
- La ligne du brouillon est plus discrète ; la vignette s'anime au survol.

## Version 0.28.22

**Des lignes de recette plus parlantes : le pass, Articles, la page d'un lot.**
- L'image à la une de l'article en vignette ; à défaut, une icône selon le
  type de lot.
- Sous le titre, chaque information derrière son icône : l'auteur (pour qui
  voit le travail de tous), le lot (lien), le type de lot, la langue, la date
  de création (« il y a 4 heures », la date exacte au survol), les étapes, la
  durée et, pour les administrateurs, le coût. L'étape en cours est nommée en
  clair.
- Un rédacteur ne voit ni montant ni nom d'auteur, comme avant.
- Sur téléphone, les boutons Modifier et Prévisualiser reprennent une taille
  normale.

## Version 0.28.21

**Affichage, sur tous les écrans.**
- Une recette terminée n'affiche plus sa barre de progression, identique et
  pleine sur chaque ligne ; elle reste pour celles qui attendent, tournent ou
  ont échoué.
- Modèles : les étapes autorisées s'alignent en deux colonnes régulières.
- Les sections repliables (Sortie, Réglages, Structures…) ont le même titre
  que les autres cartes ; les boutons de la colonne « Recettes » ne sont plus
  en police à chasse fixe ; le champ de fichier du style Facebook a l'allure
  des autres boutons.
- Réinitialiser : les boutons suivent le texte au lieu d'être repoussés en
  bas ; Diagnostic compte les tables réelles au lieu de dire « six ».

## Version 0.28.20

**Réinitialiser, et choisir ce que la suppression efface.**
- Réglages → Réinitialiser : « Rétablir les réglages » (Réglages, Moteur,
  Modèles et style Facebook reviennent aux valeurs livrées ; les clés restent
  sauf case cochée), ou « Tout effacer et rétablir » (en plus : lots,
  recettes, rapports, historique, photographies, dépenses — après avoir tapé
  un mot de confirmation, jamais pendant qu'une recette s'écrit).
- Les articles et la médiathèque ne sont jamais touchés.
- « Quand l'extension est supprimée » : supprimer réglages et données (par
  défaut, comme avant), les réglages seulement, ou tout garder.

## Version 0.28.19

**Photographies par adresse, identifiants lisibles, clés d'API.**
- Une seule zone : un clic ouvre l'ordinateur, on peut y déposer des
  fichiers, et Ctrl+V (⌘V sur Mac) n'importe où sur la page colle une image
  copiée ou l'adresse d'une image — le bouton « Coller une image » n'existe
  plus. Une image glissée depuis un autre onglet arrive par son adresse. Le
  site récupère lui-même l'image à l'envoi : https uniquement, jamais une
  adresse privée, taille plafonnée.
- Chaque photographie — envoyée, collée ou par adresse — est enregistrée une
  fois sous un nom unique qui commence par son identifiant : `lot82-photo2`.
  L'écran d'appariement et le rapport l'affichent, avec son origine
  (envoyée, collée, par son adresse) et, pour une adresse, le site d'où elle
  vient.
- Clés d'API : une carte par fournisseur, avec son état, un bouton pour
  afficher la clé saisie, le lien pour en obtenir une et le résultat de la
  vérification sur sa carte.

## Version 0.28.18

**Nouveau lot : coller une image, et un formulaire plus lisible.**
- Une image copiée — capture d'écran, photographie copiée depuis une page —
  se colle avec Ctrl+V (⌘V sur Mac) n'importe où sur la page, ou avec le
  bouton « Coller une image ». Une capture trop lourde est réduite pour
  passer la limite de taille. Un texte collé depuis Word dans les recettes
  reste du texte.
- Les recettes s'écrivent dans une police lisible ; chacune est listée sous
  le champ telle qu'elle sera lue, avec « le nom seul » ou « avec des
  précisions », et « Ajouter une recette » insère le séparateur.
- Les photographies sont numérotées ; la zone de dépôt se réduit quand il
  y en a, et « Tout retirer » vide la liste.
- Le menu MS Recipes AI est en tête du menu d'administration.

## Version 0.28.17

**L'article parle du plat, plus de la façon dont il a été écrit.**
- Chaque article mesuré contenait 7 à 10 remarques du type « les sources
  consultées ne documentent pas… » ou « température documentée ». Il n'en
  contient plus : ce qui n'est pas connu est simplement omis.
- Les consignes destinées à l'image n'apparaissent plus dans le texte
  (« assiette en céramique sobre, sans garniture ajoutée ») : l'article ne
  reçoit plus que l'aspect du plat, ses couleurs et ses textures.
- Chaque allergène et chaque règle de sécurité de la recette figure dans
  l'article ; un titre annonce ce que sa section contient.
- Pas d'explication inventée : une raison n'est donnée que si une source la
  donne. Sur trois recettes, relues par la même relecture : 28 corrections
  au lieu de 58, aucune remarque majeure au lieu de 5, même coût.

## Version 0.28.16

**Qualité du contenu : des niveaux fondés sur des mesures.**
- **Économique** mettait le modèle le moins cher (GPT-5 Nano) sur la recette,
  l'article, la relecture et le contrôle final. Mesuré le 25/09 sur trois
  recettes : recette canonique qui perd des ingrédients, articles trop courts
  et incomplets, relecture qui coûte plus et relève moins, juge en désaccord
  deux fois sur trois. Ces quatre étapes le refusent désormais, avec la
  mesure en explication sur l'écran Moteur. Économique garde donc les mêmes
  modèles, réfléchit un peu moins pour la recherche et le contrôle final
  (pas pour la relecture, qui perdait un tiers de ses corrections) et dessine
  les deux images en qualité basse : ≈ 0,079 $ au lieu de 0,085 $.
- **Premium** coûtait ≈ 0,81 $ et aurait fait refuser chaque lot sous le
  plafond de 0,15 $. Il réfléchit maintenant davantage pour la recherche, la
  relecture et le contrôle final, et dessine l'image à la une en qualité
  moyenne et le collage en haute qualité : ≈ 0,135 $. Le modèle le plus cher
  sur l'article et la relecture seuls coûterait 0,35 $ la recette.
- Standard ne change pas.

## Version 0.28.15

- **Types de lot ouverts aux rédacteurs.** Nouvelle section des réglages :
  l'administrateur coche ce qu'un rédacteur peut lancer — article seul,
  article et image à la une, ou la chaîne complète avec le collage Facebook.
  Un type décoché disparaît du formulaire des rédacteurs et est refusé s'il
  est demandé autrement. Les administrateurs gardent les trois. Il en reste
  toujours au moins un : tout décocher les rouvre tous. Vérifié sur le site
  de test : avec « article seul » seul coché, le rédacteur ne voit que lui,
  et une demande de la chaîne complète reçoit un refus (403).

## Version 0.28.14

Plus de graphiques, moins de tableaux à déchiffrer :
- **Quatorze jours en colonnes.** Une colonne par jour, la dépense comptée
  le jour où elle a été faite (appariements et nouveaux dessins compris), le
  plafond du jour en pointillés rouges quand il est fixé ; un jour qui l'a
  dépassé est rouge et chiffré. Au survol : la date, le montant, le nombre de
  recettes. Au-dessus, le total et la moyenne par jour.
- **Le juge en barres.** Pour l'article, l'image à la une, le collage et la
  cohérence : bons, réserves et mauvais dans leurs couleurs, avec leurs
  nombres et le taux de « bon » ; le tableau reste à un clic.
- **Par étape** : chaque dépense porte sa part en barre. **Contrôles qui
  échouent** : les dix plus fréquents, colorés selon leur taux, le reste replié.
- **Tableau de bord** : les plafonds du jour et des 30 jours en jauges — vert,
  orange passé 80 %, rouge une fois atteints.
- Sur téléphone, les chiffres clés passent deux par ligne.

## Version 0.28.13

- **« Où part l'argent » devient un vrai graphique.** Deux barres à 100 % —
  la dépense et le temps — découpées par poste, chaque poste dans sa couleur
  fixe (l'article, l'image à la une, le collage, le reste), avec la légende et
  les montants au-dessus. On voit d'un coup d'œil qu'une image coûte cher et
  va vite, et qu'un article est l'inverse. Au survol ou au clavier, chaque
  segment dit son poste, son montant et sa part ; le tableau chiffré reste
  en dessous. Couleurs vérifiées pour les daltoniens.
- Les durées de plus d'une heure s'écrivent « 1 h 29 min » au lieu de
  « 88 min 54 s ».

## Version 0.28.12

**Audit des coûts.** Aucun changement dans le moteur ; tout se passe dans
l'extension.
- **Chaque somme dépensée est comptée, le jour où elle l'est.** Un journal de
  dépenses reçoit une ligne par étape payée et par appariement de photographies
  d'un lot. Les plafonds du jour et du mois, la dépense sur 30 jours du tableau
  de bord et de l'analyse le lisent. Avant, la lecture des photographies d'un
  lot n'était jamais comptée, un nouveau dessin comptait au jour de création
  de sa recette, et supprimer un lot rendait son argent au plafond du jour. Il
  est rempli à la mise à jour depuis ce qui était déjà enregistré : sur le
  site de test, 6,8272 $ = 6,8087 $ de recettes + 0,0185 $ d'appariements.
- **Les tableaux d'appels tombent juste.** La lecture des photographies citées
  par la recherche est facturée sans appel détaillé ; elle apparaît désormais
  sur sa propre ligne, suivie d'un total égal au coût de la recette (rapport,
  écran de la recette, analyse par modèle).
- Le coût moyen par recette ne compte plus les recettes en attente à 0 $.
- **Écran de la recette.** Chaque verdict du juge est affiché à côté de
  l'image qu'il juge, en couleur (bon, réserves, mauvais) ; le coût se lit
  face au plafond ; les scores sont des pastilles colorées et chaque étape
  montre sa part du coût ; la liste des productions est repliée ; le déroulé
  ne montre que l'histoire, le détail s'affiche d'un clic.
- **Analyse.** Le tableau « Où part l'argent » débordait de sa carte : réparé.
  Verdicts traduits et colorés, noms d'étapes lisibles, part de l'appariement
  dans la dépense, tableau par modèle qui retombe sur le total.
- **Lot.** Ce que le lot a dépensé s'affiche à côté de son plafond. Les
  montants peints par la page suivent le format de la langue, comme ceux
  imprimés par le serveur. Une recette « à corriger » a son liseré orange.

## Version 0.28.11

Audit complet (sécurité, dix écrans × deux rôles × trois langues × ordinateur
et téléphone, tests réels sur le site) et ce qu'il a corrigé :
- **Un lot publié ne demande plus de relecture.** La phrase sous chaque
  recette disait encore « Le brouillon est prêt à être relu » ou « points à
  vérifier… avant de publier » une fois l'article publié. Elle dit maintenant
  qu'il est publié, programmé, à la corbeille ou supprimé.
- **Durées justes.** 179,8 s s'affichait « 2 min 60 s » ; c'est « 3 min 0 s ».
- **Le bouton du déroulé fonctionne sur le site.** Le rapport est servi sans
  aucun script autorisé ; le bouton « Afficher aussi les essais, appels et
  entrées » en demandait un. Il n'en utilise plus.
- **Téléchargement des photographies de référence plus sûr.** L'adresse
  vérifiée comme publique est celle à laquelle on se connecte : une seconde
  résolution du nom ne peut plus mener à une adresse interne.
- Une recette ou un lot introuvable affiche un lien de retour.
- Le test réel de bout en bout estime désormais la recette avec la
  photographie qu'il envoie : écrit depuis elle, l'article coûte 0,022 $.

## Version 0.28.10

- **Chaque plat servi comme il se sert.** Un gratin (ou un parmentier, des
  lasagnes, un clafoutis…) est montré dans le plat où il a cuit, jamais
  démoulé sur une assiette ; des gratins individuels restent dans leurs
  plats individuels ; un plat mijoté va dans le plat creux que la recette
  nomme, sinon reste dans sa cocotte ; une tarte ou une quiche reste dans son
  moule. Le reste est servi sur assiette, comme avant. La même présentation
  vaut pour l'image à la une et pour le dernier panneau du collage.
- Mesuré : un gratin de pommes de terre à la viande hachée, dans son plat,
  0,0169 $.

## Version 0.28.9

- **Image à la une moins chère.** Sa consigne ne porte plus les règles
  propres au collage (panneaux, ustensiles de chaque étape, mise en place) :
  un tiers de texte en moins, facturé au tarif du modèle d'image. Mesuré :
  0,0150 $ au lieu de 0,0161 $ pour le rôti, 0,0148 $ au lieu de 0,0159 $
  pour les croquettes.
- **Rien d'autre que le plat.** Ce qui entoure le plat sur la photographie
  de référence et que la recette ne contient pas — tomates cerises,
  garniture, bol de sauce — n'est plus recopié dans l'image à la une.
- Quand une page de lot relance des recettes en retard, ses relances
  partent de recettes différentes au lieu d'attendre toutes la première.
- Vérifié sur le site de test, profil complet avec la photographie du
  rédacteur : l'image à la une et le collage en sont partis, 0,0661 $.

## Version 0.28.8

Corrections trouvées pendant la vérification complète du code :
- Une correction qui garde la première de deux phrases la garde vraiment ;
  elle pouvait supprimer les deux.
- Une correction ne met plus en minuscule une phrase que la relecture avait
  citée correctement.
- La photographie du plat est retenue pour la recette à laquelle elle
  appartient : quand le cron du site enchaîne plusieurs recettes, l'une
  pouvait recevoir la photographie de la précédente.
- Rejouées sur 290 corrections enregistrées, 2 seulement restent à
  l'éditeur, toutes deux absentes de l'article.

## Version 0.28.7

- **Rapport plus lisible.** Il s'ouvre sur « À regarder avant de publier » :
  étapes en échec, images refusées, corrections à appliquer à la main,
  constats majeurs, avertissements — chacun menant à sa section. Un menu
  collant permet d'aller d'une section à l'autre.
- **Corrections en avant / après.** Chaque correction montre le texte retiré
  en rouge et le texte ajouté en vert ; une suppression le dit. Les
  corrections appliquées automatiquement sont repliées ; celles à faire à la
  main restent ouvertes. Les changements de langue non appliqués sont listés.
- **Déroulé en couleurs.** Chaque sorte d'événement a sa couleur (échec,
  avertissement, nouvel essai, décision, lecture, vague, étape), avec une
  légende qui les compte ; les essais, appels et entrées s'affichent d'un
  clic. L'écran d'une recette colore aussi son déroulé.

## Version 0.28.6

- **Moins de corrections « introuvables dans le HTML ».** Une citation écrite
  un peu librement par la relecture — apostrophe droite, majuscule en milieu
  de phrase, une lettre de travers — est maintenant retrouvée dans l'article
  et appliquée. Rejouées sur 290 corrections enregistrées, celles laissées à
  l'éditeur passent de 8 à 2 ; les 2 restantes citent une phrase que
  l'article ne contient pas.
- **Plus de phrase en double.** Une correction qui recopiait la phrase
  voisine supprime désormais le passage au lieu de dire deux fois la même
  chose, et la relecture peut retirer une phrase répétée même si elle porte
  un chiffre.

## Version 0.28.5

- **Image à la une : consigne + photographie du plat.** Comme le collage,
  l'image à la une est désormais dessinée avec la photographie du rédacteur,
  ou à défaut celle trouvée par la recherche, pour la forme et la cuisson du
  plat ; la consigne garde la lumière, l'assiette et le cadrage. Sans aucune
  photographie, elle est dessinée comme avant. La même photographie sert aux
  deux images et n'est téléchargée qu'une fois.

## Version 0.28.4

- **Collage de référence + consigne + photographie du plat.** Le dessin reçoit
  désormais deux images : votre collage de référence, pour le rendu, et une
  photographie du plat — celle du rédacteur, ou à défaut celle trouvée par la
  recherche — pour sa forme, sa garniture et sa cuisson. La consigne dit au
  modèle ce qu'il prend de chacune.
- Mesuré : le rôti Orloff depuis la photographie du propriétaire, 0,026 $ ;
  des croquettes depuis une photographie de la recherche, 0,024 $.

## Version 0.28.3

**Le collage garde votre rendu, même avec la photographie du rédacteur.**

- Quand le rédacteur envoyait une photographie du plat, elle remplaçait votre
  collage de référence : le collage Facebook en reprenait la lumière, le
  flash et le fond, et ne ressemblait plus à vos collages.
- Désormais votre collage de référence donne toujours le rendu (lumière,
  couleurs, plan de travail, cadrage serré), et la photographie du rédacteur
  sert seulement à montrer le plat : sa forme, sa garniture, sa cuisson. Le
  dessin est fait à partir de votre collage.
- Mesuré sur la photographie de rôti Orloff qui posait problème : 0,020 $ le
  collage.

## Version 0.28.2

**Plus d'attente : les recettes d'un lot tournent en parallèle, tout de suite.**

- Le cron de WordPress ne se déclenche qu'à la visite du site, et traite ses
  tâches l'une après l'autre : les recettes d'un lot passaient une par une,
  et chacune attendait une visite entre deux étapes — sur un site peu visité,
  elles restaient « en attente ».
- Désormais le site se relance lui-même dès qu'une recette est en file, trois
  recettes à la fois. Mesuré : trois recettes en 154 s, à peine plus que la
  plus longue seule (144 s), au lieu de plus de sept minutes à la suite.
- Si le site ne peut pas s'appeler lui-même (hébergement qui bloque ces
  appels), la page du lot, laissée ouverte, relance une recette en retard.
  Le cron reste en place derrière les deux.


- **Plafond par recette à 0,15 $** par défaut : une recette complète coûte
  environ 0,08 $, et il reste la place pour deux collages redessinés à la
  demande. Un site qui avait enregistré son propre plafond le garde ; il se
  change dans Réglages.
- **Le collage de référence est fourni avec l'extension.** C'est le collage du
  rôti Orloff qui a servi à tous les essais : un site qui n'en a téléversé
  aucun dessine désormais avec ce rendu, et votre propre collage le remplace
  dès que vous l'ajoutez dans Réglages.
- **Mise en cache des consignes.** Les consignes fixes de chaque étape sont
  envoyées de façon à être relues depuis le cache du fournisseur. Mesuré sur
  trois recettes : quelques milliers de jetons relus, soit moins d'un
  centime — le coût d'une recette tient à ce que le modèle écrit, pas à ce
  qu'il lit.
- **Le contrôle final sait enfin quelles images il a reçues** : la liste ne
  lui parvenait pas.
- **Rapport complet** : le contrôle final y est décrit comme il fonctionne
  désormais (images seules), avec le nombre de fois où chaque image a été
  dessinée, la référence d'où le collage a été composé, et les jetons relus
  depuis le cache.
- **Écrans** : les remarques du juge se lisent sur téléphone ; les photos
  mises de côté n'apparaissent plus en image cassée une fois le lot parti ;
  les descriptions des profils et le nom de l'étape de relecture disent ce
  qui se passe réellement.

Mesuré : trois recettes complètes à 0,077 $, 0,080 $ et 0,082 $, approuvées
du premier coup.

## Version 0.28.0

**Une recette complète 38 % moins chère : environ 0,077 $ au lieu de 0,124 $.**

- **Une relecture au lieu de trois.** La revue éditoriale, la vérification des
  faits et la correction de la langue sont faites en une seule lecture de
  l'article ; les corrections sont ensuite appliquées sans modèle, comme avant.
- **Le contrôle final ne regarde plus que les images.** Le texte est déjà
  vérifié par la relecture ; le juge reçoit la recette et les photographies
  de référence, deux fois moins de texte à lire, et travaille en même temps
  que la relecture.
- **Une seule recherche web** par recette au lieu de une à trois.
- **Plus de nouveau dessin automatique.** Une image refusée n'est plus
  redessinée d'office : les remarques du juge s'affichent sur l'écran de la
  recette, avec un bouton « Redessiner le collage » (ou l'image à la une).
  Le nouveau dessin reprend les remarques et remplace l'image du brouillon ;
  deux fois au plus par image.
- **Une connexion coupée est retentée une fois** au lieu d'arrêter la recette.

Mesuré : trois recettes complètes à 0,070 $, 0,077 $ et 0,078 $, toutes
approuvées du premier coup ; sur le site, un lot complet à 0,074 $ et un lot
article seul à 0,041 $ (contre 0,050 $). Un collage redessiné à la demande
coûte 0,020 $.

## Version 0.27.1

**Un collage deux fois et demie moins cher, sans emballage.**

- **Plus d'emballage au premier panneau.** Tout ce qui s'achète est montré
  sorti de son emballage, comme un cuisinier le dispose : pâte en feuille ou
  en boule sur la planche, beurre sur une assiette, crème dans un pot en
  verre. Plus de pâte sous plastique, de brique, de sachet ou de bouteille
  étiquetée, dans aucun panneau.
- **Référence réduite avant l'envoi** (768 pixels sur le grand côté) : le
  modèle la lit aussi bien, pour moitié moins de jetons.
- **Collage en qualité moyenne par défaut** : le style vient désormais de
  l'image de référence, et sur sept plats la qualité moyenne a donné le même
  rendu que la haute, pour 0,021 $ au lieu de 0,051 $. La qualité haute reste
  réglable dans l'écran Moteur.

Mesuré sur trois recettes complètes : un premier passage coûte entre 0,086 $
et 0,109 $ (contre environ 0,126 $), et 0,114 $ à 0,133 $ quand la
vérification finale fait redessiner une image — ce qu'elle a fait ici pour de
vraies fautes : un feuilleté familial au lieu de chaussons, du laurier absent
de la recette, un rôti encore cru.

## Version 0.27.0

**Le visuel Facebook dessiné comme dans ChatGPT.** Après une journée de
comparaisons avec les collages que le propriétaire obtient dans ChatGPT, la
méthode qui les reproduit — rendu et ordre des étapes — est celle de ChatGPT
lui-même, en deux temps :

1. **La consigne est rédigée d'abord.** Un modèle de texte lit la consigne du
   propriétaire (sa consigne ChatGPT, mot pour mot, avec le nom du plat), ses
   préférences, la recette — ingrédients, étapes, service — et l'image de
   référence, puis écrit la consigne détaillée des six images : ingrédients
   crus et séparés, étapes dans l'ordre de la recette, ustensiles de la
   recette (poêle pour la friture, plaque pour les feuilletés), plat ouvert à
   la fin.
2. **Le collage est dessiné avec la référence**, par GPT Image 2.5 Flare en
   qualité haute.

**La référence** est la photographie du plat envoyée par le rédacteur quand il
y en a une. Sinon, c'est un collage du propriétaire, dont seul le style est
repris : lumière, couleurs, cadrage, plan de travail — jamais le plat.
**Réglages → Style du collage Facebook** accueille jusqu'à trois collages ; le
premier sert. Sans référence, le collage est dessiné à partir du texte seul.

La vérification finale ne change pas et fait toujours redessiner un collage
fautif, avec la même consigne complétée de ce qui a été refusé. Si la
rédaction de la consigne échoue, l'ancienne consigne dessine le collage.

Coût : environ 0,002 $ pour rédiger la consigne, en plus du collage (environ
0,055 $). Une recette complète est revenue à 0,13 $ dans les essais.

## Version 0.26.8

**Le visuel Facebook suit la consigne du propriétaire.** Le texte qui décrit le
collage reprend la consigne ChatGPT du propriétaire et ses préférences :
photographie ultra réaliste comme un plat cuisiné à la maison, lumière
naturelle, cuisine moderne aux tons clairs, ombres douces, angle légèrement
vu du dessus, composition Pinterest, six images carrées en 2 × 3, mêmes
ustensiles d'une image à l'autre, progression logique jusqu'au plat tranché ou
ouvert, aucun texte, jamais l'air d'une image d'IA. Les six images vont à ce
que le lecteur veut voir — la garniture, le montage, le résultat doré,
l'intérieur — et plus à une béchamel montrée deux fois ou un bol d'œuf battu.
Un torchon vichy et quelques herbes floues au bord redeviennent permis, comme
dans ses propres collages. L'ordre de la recette et les états de cuisson
restent vérifiés.

Essayé en qualité haute sur les plats de ses collages de référence — gratin de
cabillaud, chaussons, croquettes, quiche et gratin de pommes de terre à la
viande hachée.

## Version 0.26.7

**La recette suit le plat que le rédacteur décrit.** Le titre et le texte du
rédacteur fixent la forme du plat, ses ingrédients principaux et la méthode
qu'ils nomment. Un ingrédient principal qu'ajoute une source n'entre plus dans
la recette : il devient une variante ou un accompagnement. Vérifié : le gratin
de cabillaud à la béchamel revient avec du cabillaud, une béchamel et du
fromage — les pommes de terre ne sont plus qu'une variante — et les chaussons
reviennent en chaussons individuels, plus en un grand feuilleté. Un ingrédient
listé deux fois est désormais signalé.

- **Plafond par recette : 0,25 $** par défaut (au lieu de 0,20 $), pour laisser
  la place à un second collage en qualité haute. Un site qui avait choisi son
  propre plafond le garde.
- Une réponse de recherche illisible ne met plus fin à la recette : elle est
  redemandée une fois.

## Version 0.26.6

**Le visuel Facebook en qualité haute, et moins de refus inutiles.** Décision
du propriétaire :

- **Qualité haute par défaut** pour le collage, y compris dans le préréglage
  Standard : la texture la plus fine mesurée, environ 0,056 $ le collage au
  lieu de 0,025 $.
- **Les petites fautes d'ordre ne bloquent plus.** Un aromate, une herbe, une
  épice, une garniture ou un ingrédient secondaire visible une étape trop tôt
  ou trop tard — l'ail et le thym déjà dans la marmite — est noté, sans faire
  redessiner le collage. Reste bloquant : l'ingrédient principal dans un état
  impossible à ce moment de la recette (cru après cuisson, garni avant que la
  pâte existe), un panneau répété, un plat final non ouvert.

Vérifié sur les trois recettes qui coûtaient le plus de reprises : les
chaussons approuvés au premier collage (0,120 $ au lieu de trois reprises et
0,196 $), les croquettes du premier coup (0,126 $), la potée après une seule
reprise pour un dernier panneau non ouvert (0,192 $, là où elle était refusée
trois fois pour ses aromates sans jamais être approuvée). L'estimation d'une
recette complète passe à 0,140 $.

## Version 0.26.5

**Les modèles d'image de Gemini peuvent dessiner, et sont chiffrés comme des
images.** Le moteur sait désormais demander une image à Gemini — format du
collage respecté, 2K en qualité haute quand le modèle le propose — et la
convertit au format du site. Les tarifs de ces modèles étaient ceux du texte,
dix fois trop bas pour une image : ils suivent maintenant la page de prix de
Google (image comptée au tarif image, réflexion au tarif texte), et Gemini
3.1 Flash Lite Image s'ajoute à la liste.

Comparé sur le rôti Orloff avec le même texte de consigne : la qualité haute
d'OpenAI (environ 0,056 $) garde le cadrage serré, l'ordre de la recette et le
dernier panneau ouvert ; Gemini 3 Pro Image (environ 0,15 $) a la texture la
plus nette mais cadre large ; les deux Flash ont montré un plat entier, un
emballage ou une étape dans le désordre. OpenAI reste le modèle par défaut.

## Version 0.26.4

**Le visuel Facebook prend le style voulu par le propriétaire.** Comparé à ses
collages de référence, la 0.26.3 avait pris « réaliste » dans le sens d'une
photographie d'ambiance — bois chaud, grain, flou d'arrière-plan. Ses
références sont l'inverse, et le collage les suit désormais :

- **Lumineux** : lumière du jour douce et égale, sans coin sombre.
- **Fond neutre** : un seul plan de travail clair, gris pâle ou bois clair, sans
  torchon, herbes ni accessoires.
- **Net partout** : tout l'aliment est net, avec ses détails — fibres de la
  viande, nervures du chou, lamelles des champignons —, sans grain.
- **Cadré serré et généreux** : le plat n'est jamais vu en entier, ses bords
  sortent du cadre ; la mise en place est un tas d'ingrédients serrés qui
  remplit l'image.

Les règles de réalisme (morceaux irréguliers, dorure inégale, pas d'aspect
cireux) et de séquence restent. Mis au point en trois essais sur le rôti Orloff
et la potée, comparés côte à côte avec les références ; qualité moyenne, environ
0,025 $ par collage.

## Version 0.26.3

**Un visuel Facebook qui ressemble à des photographies.** Le texte qui décrit
le collage nomme désormais ce qui trahit une image de synthèse et demande
l'inverse : morceaux coupés à la main et irréguliers, dorure inégale, coulures
de sauce, miettes, fond de cuisson dans la poêle, fibres de la viande — et
jamais de surface cireuse ou uniformément brillante, de morceaux identiques,
de symétrie parfaite ni de halo. L'image est décrite comme prise avec un vrai
appareil, avec son grain naturel.

Mesuré sur le rôti Orloff et la potée : à qualité moyenne et au même prix
(environ 0,025 $), le nouveau collage se lit comme une photographie là où
l'ancien se lisait comme un rendu. La qualité haute ajoute la texture la plus
fine pour environ 0,055 $ ; la qualité moyenne reste la valeur par défaut.

## Version 0.26.2

**Un visuel Facebook plus appétissant.** À la demande du propriétaire, après
comparaison avec des collages qu'il préférait :

- **Plus près du plat.** Chaque panneau, le premier compris, est un gros plan :
  le plat occupe presque tout le cadre, les bols et les poêles peuvent en
  sortir, plus de plan de travail vide autour.
- **Des couleurs plus vraies et plus chaudes.** Lumière chaude de fenêtre, plus
  de contraste, croûtes dorées comme la cuisson les dore — sans filtre ni
  teinte orangée. Quand une vraie photographie du plat existe, sa couleur
  reste la référence.
- **Une vraie cuisine.** Planche ou table en bois, torchon de lin ou vichy,
  herbes floues à l'arrière-plan — le même décor dans les six panneaux.
- **Le dernier panneau montre l'intérieur** : une part soulevée, une croquette
  rompue, une cuillerée. La vérification finale refuse désormais un collage
  qui finit sur le plat entier, et le fait redessiner.

Vérifié en conditions réelles sur les deux plats comparés — croquettes de
pommes de terre au jambon et quiche au poulet et aux courgettes : quatre
collages, environ 0,024 $ chacun en qualité moyenne. Le collage qui finissait
sur une quiche entière a été refusé deux fois sur deux, et celui qui l'a
remplacé est arrivé ouvert et approuvé. Une recette complète est revenue à
0,09–0,13 $.

## Version 0.26.1

**Une seule copie des prompts : celle que le moteur exécute.** Les réglages
gardaient aussi une version compilée de huit prompts, qu’un
outil recopiait et qu’un test comparait aux gabarits. Aucun écran ne les
montrait et le moteur ne les lisait pas : il compile ses propres gabarits à
chaque tâche. Ils sont retirés, avec l’outil qui les recopiait et la commande
`lab.php prompts` qui faisait la même chose.

- Un réglage retiré qu’un site a encore en base n’est plus transmis au moteur,
  et le prochain enregistrement des réglages le supprime. Le site de test en
  avait deux (des prompts d’article et de correction d’une ancienne version) :
  ignorés, puis supprimés à l’enregistrement.
- Retirés aussi : deux fichiers d’aide du laboratoire et une expérience de
  mesure dont le résultat est consigné, qui ne s’exécutait plus, et trois
  fonctions du laboratoire que rien n’appelait.
- La première section de ce fichier dit maintenant ce que fait l’extension
  aujourd’hui, au lieu d’énumérer toutes les versions ; le menu y porte son nom
  actuel, *MS Recipes AI*, et l’espagnol figure parmi les langues.

Vérifié : tests hors ligne verts ; sur le site de test, les réglages
s’enregistrent depuis leur écran sans perdre une valeur, les clés sont
acceptées, et chaque écran s’ouvre sans erreur en administrateur et en
rédacteur.

## Version 0.26.0

**Ménage en profondeur, sans changement de comportement.** Une recherche
systématique des réglages et du code que plus rien ne lit :

- **`MSRWA_Cost` est retiré** (182 lignes) : l’ancien estimateur, remplacé par
  `MSRWA_Estimate` qui lit le catalogue des modèles. Seul son test l’appelait.
- **`MSRWA_Images` ne garde que ce qui sert** — tailles et qualités d’image ;
  sa validation et ses fonctions de recadrage n’étaient appelées nulle part.
- **26 réglages morts sont retirés** : sept que rien ne lisait (dont
  « liens internes activés », qui laissait croire qu’on pouvait les couper),
  onze que seul l’ancien estimateur lisait, et huit anciens textes de prompt
  que ni le moteur ni aucun écran n’utilisaient. Les réglages d’un site qui
  les contenaient sont simplement ignorés.
- 8 traductions devenues sans objet sont retirées (690 → 682).

Vérifié : tests hors ligne verts, et chaque écran parcouru en administrateur
et en rédacteur, sur ordinateur et téléphone, sans erreur.

## Version 0.25.9

**Une longueur propre à l’arabe.** Les réglages de longueur sont comptés pour
le français ; l’arabe dit la même chose en moins de mots (il attache articles,
prépositions et pronoms au mot). Un article arabe vise désormais 85 % de la
plage : 2 040 à 2 720 mots pour les réglages par défaut. Le prompt, le
contrôle de longueur et le contrat de qualité suivent, et l’écran Réglages
l’indique sous la longueur.

## Version 0.25.8

**L’arabe se lit de droite à gauche partout.** Le rapport d’un article arabe
l’affichait aligné à gauche, les points à la mauvaise extrémité des phrases ;
sur les écrans en français, un nom de plat ou une raison en arabe faisait de
même. Chaque paragraphe prend désormais sa direction de son propre texte : un
article arabe se lit de droite à gauche dans un rapport français, et un texte
français de gauche à droite sur un écran arabe. Vérifié sur le rapport du lot
arabe et sur un rapport français, inchangé.

## Version 0.25.7

**Premier lot complet en arabe, en direct** : un tajine au poulet, olives et
citron confit, approuvé du premier coup pour 0,097 $ — recherche 15/15,
collage et image à la une, approbation finale 10/10, 9 350 caractères arabes
et aucune lettre latine dans l’article.

Il a révélé une fausse alerte : la section « choisir les ingrédients » était
déclarée absente alors que l’article la contenait (« كيف تختار… »). Le
contrôle ne connaissait que deux formes du mot, et les voyelles arabes
(« يُقدَّم ») pouvaient cacher un mot. Les titres sont désormais comparés sans
voyelles, et la section accepte les formes verbales.

À décider : l’article arabe faisait 2 033 mots pour un objectif de 2 400.
L’arabe dit la même chose en moins de mots ; un objectif propre à chaque
langue (environ 0,85 pour l’arabe) est proposé, sans être appliqué.

## Version 0.25.6

**Un lot d’une seule recette n’attribue plus d’office une photographie d’un
autre plat.** Pour économiser l’appel d’appariement, toutes les photographies
d’un lot d’une recette lui étaient données. Une photographie de yassa envoyée
avec un « Tajine de poulet » illustrait donc le tajine. Désormais l’appel est
évité seulement quand chaque plat reconnu porte le nom de la recette (« Yassa
au poulet » pour « Poulet yassa ») ; sinon l’appariement a lieu (moins d’un
dixième de centime), et la photographie d’un autre plat devient une recette de
plus. Vérifié en direct : le tajine garde son texte, le yassa devient la
seconde recette.

## Version 0.25.5

**Ménage.** Aucun changement de comportement, sauf le dernier point.

- Quatre fonctions que plus rien n’appelait sont retirées, avec leurs tests ; la
  limite de longueur est désormais testée là où elle s’applique vraiment.
- 61 traductions de textes qui n’existent plus sont retirées des catalogues
  anglais et arabe (750 → 689 entrées).
- La documentation d’architecture décrit l’historique à la place de l’ancien
  `source.json`.
- **Le rapport ne peut plus tomber sur une photographie géante** : au-delà de
  25 mégapixels, sa vignette n’est pas calculée (elle aurait demandé plus de
  mémoire que PHP n’en accorde d’ordinaire).

## Version 0.25.4

**Les photographies sources suivent l’historique.** Passé la durée de
conservation des artefacts, l’historique d’une tâche était bien effacé mais
les photographies dont elle était partie — celles du rédacteur et celle
trouvée sur le web — restaient sur le disque. Elles partent maintenant avec
lui ; le brouillon et ses images, dans la médiathèque, ne sont pas touchés.
Vérifié sur le site de test en vieillissant une tâche de 400 jours.

## Version 0.25.3

Trouvé en testant les cas limites de l’appariement, en rédacteur :

- **Un refus de lancement répondait « erreur 500 »** — lot avec une
  photographie à décider, lot déjà lancé, plafond dépassé. C’est maintenant un
  refus explicite (409), avec son message traduit.
- **Un enregistrement partiel de l’appariement effaçait les autres
  photographies** de la liste : une photographie non mentionnée restait
  ensuite « de côté » sans que personne l’ait décidé. Elle garde désormais sa
  place, et une photographie absente de l’appariement compte comme « à
  décider ».
- **Le bouton annonçait « Lancer 2 recettes » quand une seule serait écrite**
  (l’autre n’ayant plus de photographie). Il compte ce qui partira, et suit
  les choix à l’écran.
- **Le nom d’un lot restait dans la langue de celui qui l’avait enregistré**
  (« and 1 other » sur un écran arabe). Seul le titre de la première recette
  est gardé ; le reste est dit dans la langue du lecteur, y compris pour les
  lots existants.

Vérifié en direct : une recette dont la photographie est écartée n’est pas
lancée (un seul article créé sur deux recettes) ; une photographie d’un autre
lot, ou un chemin détourné, renvoie 404.

## Version 0.25.2

Trouvé en parcourant chaque écran, en administrateur et en rédacteur, en
français, anglais et arabe, sur ordinateur et téléphone :

- **Sur téléphone, « Plus tard… » débordait de l’écran** : la fenêtre de
  programmation s’ouvrait vers la droite, à 140 px au-delà du bord. Elle
  s’ouvre désormais sous le bouton, sur toute la largeur.
- **Les raisons données par l’extension restaient en français** en anglais et
  en arabe (« Seule recette du lot. », « Aucun plat reconnu… »). Elles sont
  traduites, y compris pour les lots déjà appariés ; les raisons écrites par le
  modèle restent dans la langue du lot.
- **Les boutons « Lancer » et « Programmer » désactivés disent pourquoi**, à
  côté d’eux, quand une photographie attend une décision.

Rien d’autre à signaler : aucune erreur JavaScript ni PHP, aucun débordement,
aucun montant visible d’un rédacteur, et chaque écran d’administration lui
reste fermé.

## Version 0.25.1

**Article de 2 400 mots par défaut.** Le minimum passe de 2 800 à 2 400 mots
et le maximum de 3 600 à 3 200, réparti sur deux pages d’environ 1 270 et
1 130 mots. Les deux restent des réglages ; un site qui les avait modifiés
garde les siens. Vérifié sur un article réel (quiche lorraine) : 2 727 mots,
11/11 sur son contrat.

## Version 0.25.0

**Aucune photographie n’est plus ignorée.** Dans un lot réel, une tarte aux
pommes envoyée avec un poulet yassa et un clafoutis était simplement écartée.

- **Un plat absent du texte devient une recette.** Elle est nommée d’après la
  photographie et écrite d’après elle, comme dans un lot sans texte. Plusieurs
  photographies du même plat donnent une seule recette. L’écran d’appariement
  la signale ; le rédacteur peut toujours l’écarter.
- **Une photographie sans plat reconnu attend le rédacteur.** Floue, sombre ou
  sans nourriture, elle est marquée « À décider ». Le lot ne peut être ni lancé
  ni programmé tant que le rédacteur ne l’a pas associée à une recette, n’en a
  pas fait une recette qu’il nomme (« Nouvelle recette… ») ou ne l’a pas
  écartée (« Écarter cette photographie »).
- **Une recette dont la photographie est écartée n’est pas écrite**, et l’écran
  le montre.

Vérifié sur le site de test : deux recettes et quatre photographies donnent
trois recettes — la tarte devient la troisième — et la photographie grise
attend une décision, bouton de lancement désactivé ; « Nouvelle recette… »
puis « Écarter » fonctionnent dans le navigateur. Coût de l’appariement : 0,003 $.

## Version 0.24.7

**Le rapport ne marque plus les sources comme « schéma refusé ».** Chaque
correction de la vérification des faits cite sa source ; quand c’était le
texte du rédacteur ou la pratique culinaire, le rapport la prenait pour une
adresse invalide. Elle est désormais nommée (« texte du rédacteur »,
« pratique culinaire établie », « photographie du rédacteur ») ; seule une
adresse d’un autre protocole est encore refusée, et jamais transformée en lien.

Vérifié sur trois lots réels : deux recettes à partir de trois photographies
(la photographie étrangère écartée, appariement 0,003 $, les deux recettes
approuvées du premier coup à 0,071 $ et 0,069 $, collages à six panneaux,
seules les images générées dans la médiathèque) ; quatre photographies sans
texte (trois recettes, les deux photos de tarte regroupées) ; un texte sans
photographie (recherche web, une photographie lue et gardée, 0,049 $).

## Version 0.24.6

**« Poulet yassa et 1 autres ».** Le nom d’un lot de deux recettes se lisait
ainsi en français comme en anglais (« and 1 others »). Il s’accorde désormais
au nombre, dans les trois langues. Trouvé en testant un lot réel de deux
recettes et trois photographies.

## Version 0.24.5

- **Le collage Facebook a toujours six panneaux.** Son prompt décrit six
  moments en grille de 2 × 3 ; le réglage qui permettait d’en demander de 2 à
  9 le contredisait et a été retiré.
- **Plus de caractères égarés.** Une recherche en français avait écrit
  « 润ir les pommes », avec un caractère chinois au milieu d’un mot, et obtenu
  13/13. La recherche, la recette, l’article, la vérification des faits et la
  relecture sont désormais contrôlés : une lettre d’un autre alphabet (chinois,
  japonais, coréen, cyrillique, thaï, hébreu, devanagari, ou arabe hors d’un
  article en arabe) fait échouer le contrôle, et l’étape est redemandée une
  fois. Sur 328 réponses réelles enregistrées, le contrôle ne se déclenche
  qu’une fois : sur ce cas.

## Version 0.24.4

**Une place pour un second modèle de visuel Facebook, choisi par lot.** Le
collage de préparation actuel devient le modèle `collage`, toujours par défaut.
Un modèle est un fichier de prompt et une entrée qui peut fixer son nombre de
panneaux, sa grille, son format et sa taille. Dès qu’un second modèle existe,
le formulaire d’un lot propose le choix ; avec un seul, rien ne change à
l’écran. Un modèle inconnu ou retiré ne fait jamais échouer un lot : celui du
site prend sa place. Au passage, la règle finale du collage décrit la grille à
partir du nombre de panneaux, là où elle disait toujours « 2 × 3 ».

## Version 0.24.3

**Le rapport de tâche, corrigé.** Rendu sur une tâche réelle, il montrait :
une image « introuvable » et une approbation « refusée » pour une tâche qui ne
prévoyait ni l’une ni l’autre ; la photographie du rédacteur présentée comme
trouvée sur le web, marquée « schéma refusé » et non affichée ; ni la lecture
des photographies ni son coût ; des clés anglaises (« dish identity », « pass
Non ») ; une section SEO « non renseignée ». Désormais :

- **Il s’ouvre sur l’historique** : ce que le rédacteur a fourni, la lecture et
  l’appariement de chaque photographie avec ses raisons, les corrections du
  rédacteur, le brief remis au moteur et ce qu’il en a complété.
- **Seul ce que la tâche avait prévu est montré.** Une étape prévue et non
  atteinte le dit ; une étape jamais prévue n’apparaît pas.
- **Les photographies sont montrées**, en vignettes, avec leur provenance :
  « fournie par le rédacteur » ou « trouvée par le moteur ». Le rapport d’une
  recette passe de 6,9 Mo à 0,4 Mo.
- **La lecture des photographies est comptée** : la part de la recette dans le
  coût du lot figure parmi les postes et dans le total.
- **Tout est en français**, clés et contrôles compris. Les métadonnées SEO, la
  recette et l’article libérés après le brouillon sont relus dans
  l’historique ; les images d’une tâche terminée, dans le brouillon.
- **La revue éditoriale** montre chaque constat, sa correction proposée, et ce
  que la relecture finale a changé ensuite. Le moteur ne note pas quel
  changement répond à quel constat : le rapport le dit plutôt que de le
  deviner.

## Version 0.24.2

**L’historique complet de chaque tâche.** Chaque étape est écrite une fois, et
jamais réécrite, à la fois dans une table (`msrwa_history`) et dans un fichier
numéroté du dossier de la tâche, `wp-content/uploads/msrwa/<tâche>/history/` :

1. **Ce que le rédacteur a fourni** — le texte, découpé en recettes, et les
   photographies.
2. **Lecture et appariement** — ce que chaque photographie montre, le plat
   reconnu, l’appariement proposé et ses raisons, son coût ; puis, s’il y en a,
   **les corrections du rédacteur**.
3. **Le brief remis au moteur** — la référence texte (le texte du rédacteur) et
   la référence visuelle (ses photographies et ce qu’elles montrent, ou rien si
   le moteur doit en trouver une).
4. **Le brief complété par le moteur** — le plat, ses chiffres, ingrédients et
   étapes, et les photographies d’où il les tient, y compris celle qu’il a
   trouvée sur le web.
5. **Chaque étape de création** — le prompt, la réponse, les contrôles, le
   modèle, les jetons et le coût, essai compris.
6. **Le résultat** — le brouillon, les images, le verdict final, le coût total.

L’historique suit la durée de conservation des artefacts (365 jours par
défaut) et part avec son lot ou sa tâche. Aucune clé n’y figure. Le fichier
`source.json` de la 0.24.0 est remplacé par ces étapes.

## Version 0.24.1

**Une seule photographie suffit.** La `0.24.0` demandait deux références
visuelles par recette et, avec une seule photographie du rédacteur, relançait
une recherche sur le web (environ 0,03 $ de plus par recette) : ce n’était pas
la règle voulue. Désormais une photographie lisible du rédacteur suffit, sans
recherche. Sans photographie, la recherche lit une seule photographie trouvée
sur le web au lieu de trois. L’estimation compte de nouveau une recette illustrée
par photographie.

## Version 0.24.0

**Les photographies des rédacteurs ne vont plus dans la médiathèque.** La
médiathèque ne contient plus que les images des articles — image à la une et
collage. Les photographies envoyées avec un lot sont des sources de travail :
elles sont gardées dans `wp-content/uploads/msrwa/`, fermé au web, et ne
s’affichent qu’à ceux qui peuvent voir le lot.

- **Un dossier par recette.** À l’envoi du lot, les photographies de chaque
  recette rejoignent son dossier avec un fichier `source.json` : ce que le
  rédacteur a fourni (texte, photographies et ce que chacune montre), puis ce
  que le moteur a complété (le plat, ses ingrédients et ses étapes) et les
  photographies trouvées sur le web qu’il a lues, gardées dans `references/`.
  Supprimer le lot ou la recette supprime son dossier.
- **Plus d’erreur « le fichier existe déjà ».** Une photographie est nommée
  d’après son contenu : envoyée deux fois, dans un lot ou dans deux, elle est
  un seul fichier.
- **Chaque photographie n’est lue qu’une fois.** L’appariement la lit avec les
  consignes d’observation du moteur, et la recherche reprend cette lecture au
  lieu de payer un second regard. Vérifié : recherche 13/13 depuis deux
  photographies, sans recherche web ni seconde lecture, 0,0049 $.
- **Un lot d’une seule recette n’est plus apparié par un appel payant** : ses
  photographies sont les siennes.
- **Deux références visuelles par recette.** Avec au moins deux photographies
  lisibles, la recherche s’en contente ; avec une seule, elle cherche sur le
  web le complément, la photographie du rédacteur en tête. Le réglage
  `research.min_photographs` du moteur le fixe, et l’estimation le suit.

Les lots envoyés avant cette version gardent leurs photographies dans la
médiathèque ; elles partent avec leur lot comme avant.

## Version 0.23.5

**La recherche ne s’arrête plus sur son plafond.** Écrite d’après les
photographies du rédacteur, sans recherche sur le web pour la borner, une
recherche a produit 17 673 caractères de listes, atteint le plafond de 12 000
jetons avant de fermer son objet et n’a rien donné d’exploitable (0/13), tout
en étant facturée. Les deux prompts de recherche plafonnent désormais chaque
liste — substitutions, accompagnements, erreurs, conservation, sécurité,
incertitudes — à quelques entrées d’une phrase courte, et le plafond passe à
16 000 jetons comme marge. Un plafond ne coûte rien tant qu’il n’est pas
atteint.

## Version 0.23.4

**Le collage Facebook montre la recette, pas les ustensiles.** Deux collages
réels — une quiche, une tarte aux figues — dessinaient en troisième case un fond
couvert de papier cuisson et de billes, et répétaient le fond cru dans les deux
premières. Trois causes : la règle d’ordre exigeait une case par passage au
four, le texte de l’étape nommait les billes, et la mise en place autorisait un
fond déjà foncé.

- **Une cuisson à blanc se montre par son résultat** : le fond doré, sec et
  vide après sa cuisson. Papier, billes, poids et papier d’aluminium sont
  interdits dans toutes les cases, même quand l’étape les nomme. Sur dix
  tirages réels, aucune bille.
- **La mise en place ne montre que des ingrédients crus** et l’ustensile vide,
  rien de ce qu’une case suivante montre en train d’être fait.
- **Six cases, deux colonnes sur trois rangs, toujours.** Une recette de sept
  étapes revenait en huit cases : le calcul est maintenant dit au modèle (le
  plat fini prend la dernière case, cinq moments pour les autres). Une recette
  de moins de six étapes ouvre sur la mise en place, se termine sur le plat, et
  remplit les cases restantes avec les deux états visibles d’une même étape —
  la pâte versée, puis le gâteau cuit — sans jamais inventer d’étape ni laisser
  de case vide. Vérifié sur un gâteau en quatre étapes.
- **La vérification finale bloque désormais** un panneau dont le sujet est un
  ustensile plutôt que la nourriture, et une case vide. Elle laissait passer le
  panneau aux billes parce qu’elle ne bloquait une image que si elle n’était pas
  crédible, montrait un autre plat ou inversait l’ordre.

## Version 0.23.3

**Audit complet** — sécurité, chaque écran en tant qu’administrateur et
qu’auteur, en français, anglais et arabe de droite à gauche, sur ordinateur et
téléphone, et la cohérence des derniers changements. Ce qui a été trouvé et
corrigé :

- **De l’argent atteignait un rédacteur.** La route `GET /queue`, ouverte aux
  rédacteurs, renvoyait les plafonds quotidien et mensuel du site et ce qui
  avait été dépensé. Un rédacteur n’y apprend plus que si le site peut encore
  dépenser.
- **Une seule règle pour administrer.** Les écrans de gestion s’ouvrent sur
  `msrwa_manage`, les routes derrière leurs boutons exigeaient
  `manage_options` : quelqu’un à qui l’on donne la première voyait les
  boutons et recevait un refus. Tout passe désormais par `MSRWA_Rights`.
- **Tous les pluriels arabes étaient faux.** Le compilateur des catalogues
  laissait tomber l’en-tête, donc la règle des pluriels : WordPress appliquait
  les deux formes de l’anglais, et « 1 recette » s’affichait « aucune
  recette ». Les six formes de l’arabe sont désormais justes, et un test vérifie
  chaque catalogue.
- **« undefined/undefined »** remplaçait le compteur d’étapes d’un rédacteur
  qui suivait son lot : l’avancement lui est rendu, sans le nom technique de
  l’étape ni le coût.
- **Désinstaller laissait la table du catalogue.** Elle est supprimée avec les
  autres, et un test compare la liste aux tables du code.
- **Lot fait de photographies seules sur un site non francophone** : les
  titres de recettes, les descriptions et les raisons de l’appariement étaient
  demandés en français ; ils le sont dans la langue du site.
- Sur téléphone, le tableau des niveaux de l’écran Modèles débordait de 15 px.

Vérifié : aucune erreur de script, aucun avertissement PHP, aucun débordement
sur les dix écrans, dans les trois langues, aux deux largeurs ; aucun montant
sur un écran qu’un auteur peut ouvrir ; les cinq écrans de gestion lui sont
refusés ; le contenu d’un article traverse le filtre d’un auteur à l’octet près.

## Version 0.23.2

**Articles et images arrivent dans WordPress comme si le rédacteur les avait
enregistrés lui-même.** Le brouillon est créé par le cron, où personne n’est
connecté : chaque crochet que WordPress et les extensions du site exécutent à
l’enregistrement d’un article ou à l’envoi d’un fichier voyait « personne ».

- Pendant la création, l’utilisateur courant est le rédacteur du lot, puis
  l’utilisateur précédent est rétabli. L’article porte « Dernière modification
  par » (`_edit_last`) comme après un enregistrement dans l’éditeur.
- Les images générées entrent dans la médiathèque par `media_handle_sideload()`,
  le chemin de WordPress pour ajouter un fichier : les filtres d’envoi
  (`wp_handle_upload_prefilter`, `wp_handle_upload`) dont dépendent les
  extensions de déport, d’optimisation ou de WebP s’exécutent, les tailles sont
  générées et l’auteur est le rédacteur. Les photographies du rédacteur
  passaient déjà par ce chemin.

Vérifié de bout en bout : recette approuvée pour 0,066 $, article et trois
médias au nom du rédacteur, image à la une ramenée à 70 Ko par MS Image
Optimizer, quatre champs remplis sur chaque image.

## Version 0.23.1

**Les images générées ont un auteur.** L’article est écrit par le cron, où
personne n’est connecté : l’image à la une et le collage entraient dans la
médiathèque sans auteur. Ils portent désormais l’auteur de leur article, et
l’installation attribue ceux déjà générés (500 au plus par passage, seulement
ceux qui n’en ont pas). Vérifié sur le site de test : les trois médias d’un
article affichent leur auteur.

## Version 0.23.0

**MS Image Optimizer traite enfin les images générées — compression et
redimensionnement compris.** Quatre causes, trouvées sur le site de test :

- L’identifiant du collage était rangé sous `_msrwa_facebook_image_id`.
  L’optimiseur lit toute méta contenant « image » comme un contenu d’article :
  il créait des tâches « contenu », puis les sautait avec « Image is no longer
  assigned to this role ». Les clés deviennent `_msrwa_{type}_generated` ; la
  migration les renomme et rend les articles concernés à l’optimiseur.
- L’optimiseur refuse de toucher une image quand PHP accorde moins de
  45 secondes ; 30 est courant. Ses propres tâches reçoivent désormais
  180 secondes (filtre `msrwa_image_optimizer_seconds`), rien d’autre.
- Le modèle d’image répond en WebP **sans perte**, et WordPress garde un WebP
  sans perte sans perte à chaque réencodage : la qualité 78 de l’optimiseur
  était ignorée, l’image à la une restait à 1,1 Mo. Elle est désormais
  enregistrée avec perte, qualité 90 (filtre `msrwa_generated_webp_quality`) ;
  les images déjà générées sont converties une fois par la migration.
- Avant de modifier une image, l’optimiseur cherche son identifiant dans toutes
  les métas et options ; un collage qui était la pièce jointe 150 était pris
  pour partagé parce qu’une recette cuit 150 minutes. Les chiffres de recette,
  les métas de l’extension et les tailles d’image ne sont plus cherchés.

Mesuré, recette complète en espagnol : image à la une 82 Ko après
l’optimiseur (contre 1,1 Mo), collage 462 Ko à la qualité Facebook de
l’optimiseur (100), fichiers nommés `{slug}.webp` et `{slug}-fb-1.webp` comme
chez MS Cook Writer.

**Les deux images portent leurs quatre champs** : texte alternatif et titre
d’après le titre SEO, légende et description d’après la description SEO —
la correspondance même de l’optimiseur, qui n’a donc rien à réécrire.

**Espagnol.** Quatrième langue d’article : sections exigées en espagnol, titre
de la page deux, `og:locale` `es_ES`. Mesuré : « Tarta de manzana normanda »,
article 10/10, approuvée au premier contrôle, 0,0695 $ en 210 s. Sur l’écran
Moteur, la « langue par défaut » était un champ libre que plus aucun lot ne
lisait ; c’est désormais une liste des quatre langues qui enregistre le
réglage même de l’écran Réglages.

**Préréglages de qualité sur l’écran Moteur** : Économique (≈ 0,081 $ la
recette), Standard (≈ 0,110 $, la configuration livrée), Premium (≈ 1,091 $).
Chacun règle d’un coup le modèle, la réflexion et la qualité d’image de chaque
étape, avec les mêmes refus que le tableau ; toute modification à la main
affiche « Personnalisé ». Un préréglage au-dessus du plafond le signale.

**Plafond par recette par défaut : 0,20 $.** Un site qui a déjà réglé le sien
le garde.

**L’écran Articles suit chaque article dans WordPress.** Onglets avec leurs
nombres — brouillons, programmés, publiés, corbeille, supprimés de WordPress,
sans article — et, sur chaque recette, l’état et la date du billet, son titre
s’il a été renommé, et les actions : modifier, voir ou prévisualiser,
restaurer. La recherche trouve un billet sous ses deux noms.

**Un article publié n’est plus « à relire » ni « à corriger ».** Une fois le
billet publié, programmé, mis à la corbeille ou supprimé, il quitte « Demande
une décision » et les compteurs du pass : l’éditeur a décidé.

**Les actions groupées de l’écran Articles fonctionnent de nouveau** : aucune
case ne se cochait, une variable du script en écrasant une autre.

## Version 0.22.0

**Une recette illustrée par le rédacteur n’est plus cherchée sur le web.**
La recherche est écrite d’après ses photographies et son texte, avec la
pratique culinaire établie pour le reste ; sans photographie, elle cherche sur
le web comme avant. Réglable dans le groupe `research` de l’écran Moteur :
`without_images` (par défaut) ou `always`.

- Mesuré sur le site de test, une tarte avec sa photographie : recherche à
  0,0054 $ en 30,6 s, 13/13, contre 0,022–0,028 $ et 42–79 s pour les dix
  recherches précédentes sur le web ; la recette a été approuvée.
- **Correction :** les photographies du rédacteur n’atteignaient jamais la
  recherche. Le moteur lisait une clé que l’extension ne remplit pas, puis
  refusait toute adresse qui n’est pas en HTTPS public ; chaque run avec
  photographie enregistrait « image URL is not HTTPS » sous un message disant
  l’image lue. Elles sont désormais lues depuis les fichiers du site, et
  seulement si un rédacteur les a envoyées.
- La lecture de ces photographies est désormais comptée dans le coût de la
  recherche ; elle ne l’était pas.
- Une photographie illisible fait revenir à la recherche sur le web, et le
  run le dit.
- L’estimation d’un lot compte une recette par photographie, au plus, comme
  écrite d’après elle.

**L’écran d’appariement.**

- Les recettes d’abord, en cartes : chacune montre ses photographies et dit
  si elle sera écrite d’après elles ou d’après le web. Les cartes suivent
  chaque changement.
- Les photographies ensuite, plus grandes et ouvrables en grand, avec le plat
  reconnu, un badge de confiance en couleur, et un liseré sur celles qui sont
  à vérifier. Une photographie mise de côté le dit, au lieu d’afficher
  « association sûre ».
- L’appariement s’enregistre à chaque changement ; le bouton
  « Enregistrer » disparaît. Une photographie que le rédacteur n’a pas touchée
  garde la confiance et la raison données par le modèle : chaque
  enregistrement les écrasait toutes.
- Une note signale les photographies qui ne vont avec aucune recette.
- « Lancer » est l’action principale, dans une barre qui reste visible ;
  la programmation est repliée derrière « Plus tard… ». Sur téléphone, tout
  tient à l’écran.
- Un lot parti montre l’appariement en texte, plus en listes désactivées.

## Version 0.21.0

**Un lot se compose de texte, de photographies, ou des deux.**

- Texte seul : comme avant, sans l’appel d’appariement qui était payé pour
  rien quand il n’y avait aucune photographie.
- Photographies seules : chacune est décrite, puis un appel de texte les
  regroupe par plat ; chaque plat devient une recette nommée d’après ce que
  montrent ses photographies, à établir d’après les sources. Deux vues d’une
  même tarte font une recette ; une photographie où aucun plat n’est reconnu
  n’en fait aucune. Si aucun plat n’est reconnu, le lot est refusé et ses
  photographies retirées. L’écran du lot dit que les recettes ont été nommées
  d’après les photographies, pour qu’on les vérifie avant de lancer.
- Les deux : inchangé.
- Mesuré sur le site de test : deux photographies sans texte, une tarte aux
  pommes et une souris d’agneau, ont donné deux recettes justement nommées ;
  le regroupement a coûté 0,0007 $ en 6 secondes.

**Les écrans Modèles et Moteur appliquent la même règle.** Les deux lisent
`MSRWA_Compat` :

- la même liste d’étapes, dans le même ordre et sous les mêmes noms — la
  lecture des photographies apparaît désormais sur l’écran Modèles ;
- les mêmes modèles : le Moteur propose, étape par étape, chaque modèle activé
  sur l’écran Modèles et de la bonne famille (texte ou image), et plus seulement
  trois niveaux ; un niveau reste proposé sous le nom de son modèle,
  « GPT-5.6 Luna · standard » ;
- les mêmes refus : un modèle désactivé sur l’écran Modèles ne sert plus
  aucune étape — l’enregistrement et le lancement le refusent — et il est grisé
  sur le Moteur avec la raison ;
- les mêmes mots : économique, standard, avancé sur les deux écrans.

Le tableau « Modèles et tarifs » du Moteur, qui lisait la liste livrée avec
l’extension plutôt que le catalogue du site, est remplacé par un lien vers
l’écran Modèles.

## Version 0.20.2

**Le modèle et la réflexion ne se confondent plus.** Chaque étape offrait deux
listes où revenaient les mêmes mots — `low`, `medium`, `high` — pour deux
réglages différents : le premier choisit *quel modèle* travaille, le second
*combien il réfléchit*. Lus côte à côte, ils passaient pour le même réglage
donné deux fois.

- Le niveau est nommé par le modèle qu’il désigne chez le fournisseur choisi :
  « GPT-5.6 Luna · standard », « Gemini 3.1 Flash-Lite · économique ».
- La réflexion porte ses propres mots : minimale, légère, moyenne, poussée ;
  la qualité d’une image : basse, moyenne, haute. Chaque liste a son nom
  au-dessus d’elle.
- Trois encadrés, en tête du tableau, disent ce que décide chacun : le modèle
  fixe le prix du jeton, la réflexion le nombre de jetons, la qualité le prix
  d’une image.
- Le modèle qui tournera, son tarif et ses éventuels blocages s’affichent sous
  les listes au lieu d’une colonne à part.

**L’écran Moteur tient sur un téléphone.** Les tableaux des étapes et des
modèles deviennent des fiches à deux colonnes au lieu de défiler de côté ; les
listes du choix des modèles prennent toute la largeur. Plus rien ne dépasse de
l’écran à 390 px.

**Un seul tableau des étapes.** Le tableau « Où irait chaque étape » répétait
celui des étapes, en anglais sans accents et avec un modèle de texte sur les
deux images ; ce qu’il ajoutait — clé présente, tarif connu, plafond de
sortie, tentatives — est passé dans le tableau des étapes. Le JSON transmis au
moteur est replié, pour le dépannage.

## Version 0.20.1

**Une étape ne peut être confiée qu’à un modèle capable de la faire.** La
recherche confiée à OpenAI · `low` échouait : ce niveau désigne gpt-5-nano, qui
sait chercher sur le web sur le papier mais, mesuré sur la brochure de test,
consacre toute sa réponse à réfléchir entre deux recherches et s’arrête au
plafond de 12 000 jetons sans rien rendre (0/14 champs, même en réflexion
`low`). Avec un plafond de 32 000 jetons il rend 9/14 champs, sans aucune
photographie de référence, pour 0,068 $ et 130 s — 2,7 fois le coût de
GPT-5.6 Luna, qui rend 14/14 pour environ 0,025 $. gpt-5.4-mini est coupé de
même (0/14, 0,11 $).

- Chaque étape exige ce dont elle a besoin : la recherche un modèle de texte qui
  cherche sur le web, l’approbation finale et la lecture des photographies un
  modèle de texte qui lit les images, les images un modèle qui dessine. Une
  capacité que rien n’établit compte comme absente.
- Les modèles mesurés inaptes à une étape y sont refusés, avec la mesure en
  motif ; un modèle retiré d’une étape sur l’écran Modèles l’est aussi.
- Sur l’écran Moteur, les choix refusés sont grisés avec leur motif, et changer
  de fournisseur passe au premier niveau qui convient. Un enregistrement qui
  confierait une étape à un modèle incapable est refusé en entier.
- Sur l’écran Modèles, les étapes qu’un modèle ne sait pas faire ne peuvent
  pas être cochées.
- Un lot dont une étape tomberait sur un modèle incapable est refusé avant
  toute dépense ; le diagnostic le signale.
- Un modèle récupéré depuis le fournisseur garde les capacités que l’extension
  connaît déjà quand la liste du fournisseur ne les mentionne pas.

## Version 0.20.0

**Le brouillon parle la langue du reste de la pile MS.** Chaque champ est
écrit dans le format que son lecteur analyse, et rien n’est inventé pour en
remplir un : une valeur que la recette n’a pas reste vide, parce que chacune
atteint un moteur de recherche comme un fait sur le plat.

| Champ | Lu par | Rempli ? |
| --- | --- | --- |
| `_recipe_prep_time`, `_recipe_cook_time` | fiche recette du thème, MS SEO Plus, JSON-LD | oui, en minutes entières |
| `_recipe_servings`, `_recipe_calories` | fiche, JSON-LD (`recipeYield`, `nutrition`) | oui (les calories sont l’estimation de la recette) |
| `_recipe_ingredients`, `_recipe_instructions`, `_recipe_equipment`, `_recipe_notes` | fiche, JSON-LD | oui, une ligne par élément, quantité en tête pour le calcul des portions |
| `_recipe_cuisine`, `_recipe_keywords` | fiche, JSON-LD | oui |
| `_recipe_difficulty` | fiche | oui, en `easy` / `medium` / `hard` ; vide si la recette dit autre chose |
| `_recipe_faq` | MS SEO Plus | oui |
| `_seo_title`, `_seo_description` | thème, MS SEO Plus, MS Image Optimizer | oui, coupés à un mot sous 60 et 155 caractères |
| `fb_images_data` | MS FB Posts | oui : le collage et la légende Facebook |
| Catégorie | thème, JSON-LD (`recipeCategory`) | oui, parmi les catégories existantes (« plat principal » → « Plats principaux ») ; aucune n’est créée, la suggestion reste sur le brouillon sinon |
| Étiquettes, extrait, identifiant d’URL, titre | WordPress, thème | oui |
| Image à la une, texte alternatif | thème, Open Graph, JSON-LD | oui, avec des recadrages 4:3 et 16:9 pour les résultats de recettes |
| Photographies du rédacteur | médiathèque | oui, attachées au brouillon |
| Canonique, Open Graph, Twitter, fil d’Ariane | le thème (ou MS SEO Plus) | produits par eux ; l’extension n’imprime plus les siens en double |
| JSON-LD Recipe | le thème (ou MS SEO Plus) | produit par eux, enrichi par l’extension : trois formats d’image, ustensiles (`tool`), langue, catégorie de la recette si l’article n’en a pas, durée totale réelle quand la recette repose |
| `_recipe_video` | fiche, JSON-LD | **non** : aucune vidéo n’est produite |
| `_recipe_rating_sum`, `_recipe_rating_count` | fiche, `aggregateRating` | **non**, volontairement : seuls les votes réels comptent |
| `_seo_noindex` | thème, MS SEO Plus | **non**, volontairement : l’article est indexable |
| Biographie de l’auteur | thème (E-E-A-T) | **non** : c’est le profil WordPress de l’auteur |
| `_ms_recipes_sidebar` | thème | **non** : choix de mise en page du site |

**La langue et le plafond sont ceux des réglages.** Le formulaire *Nouveau
lot* ne les demande plus ; il les rappelle, avec un lien vers les réglages pour
un administrateur. Un lot ne peut plus porter son propre plafond ni sa langue,
même par l’API.

**Les photographies se déposent.** L’étape devient une zone où glisser ses
fichiers, avec des vignettes carrées, le nom et le poids de chacune, un bouton
pour la retirer, et la raison du refus sur celle qui ne passerait pas.

**Corrigé en chemin.** La règle « une photographie sans plat reconnu n’est
pas associée » (0.18.9) lisait les photographies avant leur description et
désassociait donc toutes les photographies. Le test réel l’a trouvé avec une
vraie photographie de tarte ; elle est désormais associée et attachée au
brouillon.

Vérifié en réel avec le thème MS Recipes, MS FB Posts et MS Image Optimizer
actifs : une recette complète en 211 s pour 0,088 $, une seule balise
description, un seul canonique, un seul graphe Recipe, la fiche sans aucun
JSON brut, la catégorie « Desserts » trouvée seule.

## Version 0.19.0

**Moins cher, sans perte mesurée.** Deux leviers, mesurés en réel sur six
recettes (tarte normande, poulet yassa, souris d’agneau, daube provençale,
lasagnes courgettes-jambon, clafoutis), réglages par défaut, OpenAI :
**6 recettes sur 6 approuvées**, article, image à la une et collage jugés
*good* à chaque fois.

- *La correction de la langue ne réécrit plus l’article.* Elle renvoie
  seulement les phrases qu’elle corrige, citées telles quelles, et le moteur
  les remplace dans le texte. Une correction qui toucherait un chiffre est
  refusée ; une citation introuvable reste signalée à l’éditeur. 3 200 jetons
  au lieu de 6 200, 32 s au lieu de 49 s, 0,006 $ au lieu de 0,0096 $.
- *Les prompts d’image disent chaque règle une fois.* Une image est facturée
  surtout sur le texte qu’on lui envoie : le prompt du collage répétait trois
  fois la liste des ingrédients, trois fois l’interdiction de garniture et deux
  fois les observations. Toutes les règles sont gardées, chacune une fois :
  16 500 → 9 700 caractères pour le collage, 0,029 $ → 0,023 $ ; 7 500 → 5 600
  pour l’image à la une, 0,015 $ → 0,013 $.

| Recette | Passages d’approbation | Coût |
| --- | ---: | ---: |
| Tarte aux pommes normande | 1 | 0,090 $ |
| Souris d’agneau au four | 1 | 0,091 $ |
| Daube de bœuf provençale | 1 | 0,099 $ |
| Lasagnes courgettes et jambon | 2 (une phrase corrigée) | 0,102 $ |
| Poulet yassa | 2 (collage redessiné) | 0,131 $ |
| Clafoutis aux cerises | 4 (images redessinées) | 0,177 $ |
| **Moyenne** | | **0,115 $** (0,125 $ en 0.18.5) |

Une recette approuvée du premier coup coûte désormais 0,09 à 0,10 $ ; ce qui
fait monter la moyenne, ce sont les images redessinées. Le collage du clafoutis
montrait deux fois la même étape : la règle « chaque panneau fait avancer la
recette » avait disparu à la réécriture et a été rétablie. L’estimation suit
ces mesures : 0,110 $ la recette, au plus 0,317 $.

## Version 0.18.9

**Chaque écran repris après une revue complète dans le navigateur**, en
administrateur et en auteur, sur ordinateur et sur téléphone.

- *Première visite.* Un rédacteur qui n’a encore rien envoyé ne voit plus
  quatre zéros : le pass explique en trois étapes ce qui va se passer — déposer
  un lot, vérifier l’appariement, relire les brouillons — avec le bouton pour
  commencer.
- *« À corriger ».* Un article refusé par le contrôle final s’affichait
  « réserves du juge » ; il dit maintenant ce que l’éditeur a à faire.
- *Actions groupées.* La liste des articles proposait « Arrêter » par défaut :
  un « Appliquer » distrait arrêtait les recettes cochées. Il faut désormais
  choisir l’action.
- *Appariement.* Une photographie sur laquelle aucun plat n’est reconnu
  n’est plus jamais associée d’office, même si le modèle s’en dit sûr : un
  aplat brun avait été rattaché à une tarte « avec confiance ». Le rédacteur
  l’associe à la main si elle appartient à une recette.
- *Analyse.* Le bloc du juge comptait un seul article jugé sur vingt-six : il
  lisait une copie du verdict que l’extension efface une fois le brouillon
  créé. Il lit désormais la décision sur la recette et les remarques dans le
  brouillon.
- *Moteur.* Les groupes JSON sont repliés (ils s’ouvrent d’eux-mêmes quand
  l’un d’eux a été modifié) : la page passe de 9 775 à 4 874 pixels de haut. Les noms d’étapes et les descriptions des groupes sont
  traduits, et le registre des étapes indique le vrai modèle des images et
  « aucun » pour les corrections appliquées en code.
- *Modèles.* Les cases « étapes qu’il a le droit de servir » débordaient de la
  carte ; elles passent à la ligne, traduites, et un modèle d’image ne se voit
  plus proposer que les étapes d’image (et inversement).
- *Recette.* Les avis du juge se lisent en texte courant et non plus en
  police à chasse fixe ; les contrôles non tenus passent à la ligne au lieu
  de sortir de la carte ; « porte sur » dit « collage » plutôt que
  `facebook_image`.
- *Menu.* Sur les pages d’un lot ou d’une recette, le menu « MS Recipes AI »
  reste ouvert, avec le pass ou les articles en surbrillance.

## Version 0.18.8

**L’extension est réservée à ceux qui peuvent téléverser.** Un lot fait entrer
des photographies dans la médiathèque ; l’extension exige donc désormais la
capacité WordPress `upload_files` en plus de la sienne. Par défaut : auteurs,
éditeurs et administrateurs. Un contributeur ne voit ni le menu, ni les
écrans (refusés même par leur adresse), ni l’encadré dans l’éditeur
d’articles, et l’API REST lui répond 403 — même s’il détenait la capacité
`msrwa_create` d’une version précédente.

**Le menu s’appelle « MS Recipes AI »**, de même que l’encadré de l’éditeur
d’articles et l’intervalle de cron. Le nom de l’extension dans la liste des
extensions reste « MS Recipes Writer AI ».

Vérifié en réel : un contributeur portant `msrwa_create` n’a pas de menu, la
page *Nouveau lot* lui répond 403, la file et la création de lot aussi par
l’API ; un auteur voit « MS Recipes AI » et crée un lot.

## Version 0.18.7

**Le plafond par recette est décrit tel qu’il agit.** L’écran Réglages et le
formulaire de lot disaient qu’une recette dont le coût maximal dépasse le
plafond « serait arrêtée avant la fin ». Ce n’est plus vrai depuis la 0.18.1 :
la recette se termine toujours, le plafond arrête seulement les nouvelles
tentatives de l’approbation finale avant qu’elles ne le franchissent, et le
dernier verdict va au rédacteur. Aux réglages par défaut (0,30 $ par recette,
estimée à 0,121 $, au plus 0,349 $), l’écran Réglages ne s’affiche plus en
avertissement : il ne le fait que lorsque le coût attendu lui-même dépasse le
plafond, le seul cas où un lot est refusé.

## Version 0.18.6

**Les photographies envoyées vont avec leur brouillon.** Une photographie
envoyée avec un lot sert de référence à la recherche ; elle restait ensuite
dans la médiathèque sans rien qui la relie à l’article. Elle est désormais
attachée au brouillon de sa recette, là où l’éditeur la cherchera. Si le lot
est supprimé avant qu’un brouillon ne la prenne, elle part avec lui. Seules
les photographies ajoutées par l’extension sont concernées : rien d’autre de
la médiathèque n’est jamais déplacé ni supprimé.

Vérifié en réel : la suite complète passe (4 sur 4). Une recette complète
envoyée avec une photographie, comme le fait le formulaire, a pris 226 s pour
0,104 $, et la photographie est attachée au brouillon avec l’image à la une et
le collage. Un lot supprimé sans avoir tourné a emporté sa photographie.

## Version 0.18.5

**Mesuré en réel, aux réglages par défaut**, le même lot de trois recettes
qu’en 0.18.3, le site n’étant visité qu’une fois par minute (l’équivalent d’un
cron serveur) : **3 recettes sur 3 approuvées**, **0,125 $ la recette** en
moyenne (0,132 $, 0,126 $, 0,118 $ ; estimé 0,121 $), **8 min 06 s** pour le
lot entier, contre 14 min 18 s en 0.18.3 avec une visite toutes les 20 s.

| Étape | Durée moyenne | Coût moyen | Estimé |
| --- | ---: | ---: | ---: |
| Recherche (web, ≤ 3 recherches) | 47 s | 0,0291 $ | 0,0369 $ |
| Recette canonique | 19 s | 0,0033 $ | 0,0040 $ |
| Article | 49 s | 0,0085 $ | 0,0087 $ |
| Image à la une (`low`) | 10 s | 0,0149 $ | 0,0149 $ |
| Collage Facebook (`medium`) | 15 s | 0,0293 $ | 0,0284 $ |
| Revue | 18 s | 0,0037 $ | 0,0043 $ |
| Vérification des faits | 43 s | 0,0073 $ | 0,0075 $ |
| Correction de la langue | 49 s | 0,0096 $ | 0,0105 $ |
| Approbation finale (1 ou 2 passages) | 43 s | 0,0103 $ | 0,0061 $ |
| Collage redessiné (1 recette sur 3) | 16 s | 0,028 $ | — |
| **Recette complète** | **282–334 s** | **0,125 $** | 0,121 $ |

**Un lot n’attend plus un visiteur entre deux étapes.** Le cron de WordPress
ne se déclenche qu’à une visite ; chaque recette rendait la main après chaque
vague — sept par recette — et attendait la visite suivante. Un passage de
cron enchaîne désormais les vagues d’une recette pendant 150 s au plus, en
enregistrant chacune avant la suivante : une requête interrompue ne coûte
toujours qu’une vague.

**L’approbation finale ne juge plus une phrase déjà corrigée.** Le journal
des modifications de la correction (« avant », « après ») partait avec
l’article ; le juge y lisait l’ancienne phrase et refusait l’article pour un
défaut qui n’existait plus.

**Un nombre dans le collage n’est plus bloquant.** Trois carottes sur la
planche au lieu de deux, un bol qui semble contenir plus de pommes de terre :
le prompt de l’approbation disait déjà qu’un nombre est mineur, mais le juge
l’appliquait à la liste des ingrédients et pas au collage, ni quand le brief
visuel donne un nombre. Les redessins du collage — 0,03 $ chacun — passent de
six à un sur trois recettes. Un ingrédient ajouté ou une étape dans le
désordre restent bloquants.

## Version 0.18.4

**Les photographies viennent de l’ordinateur, plus de la médiathèque.** Sur
l’écran *Nouveau lot*, le bouton « Choisir dans la médiathèque » devient
« Choisir sur mon ordinateur » : le rédacteur sélectionne ses fichiers, en
voit les vignettes, et ils partent avec le lot. Le serveur les vérifie
d’après leur contenu — JPEG, PNG ou WebP, taille maximale selon le moteur et
le serveur (affichée sous le bouton), 30 au plus — puis les ajoute à la
médiathèque au nom du rédacteur. Un seul fichier refusé refuse le lot entier
avec son nom, et rien n’est laissé dans la médiathèque ; une photographie de
la médiathèque ne peut plus être glissée dans un lot, même par la REST API.
La taille et le type sont aussi vérifiés dans le navigateur, avant l’envoi.

Vérifié en réel (`tests/real/test-upload.php`) : un faux `.jpg` refuse le lot
sans rien ajouter à la médiathèque ; une vraie photographie devient une pièce
jointe du rédacteur et la photographie du lot. Le formulaire lui-même a été
rempli dans Chromium : fichier choisi, vignette, lot créé.

## Version 0.18.3

**Mesuré en réel, réglages par défaut.** Un lot de trois recettes (tarte aux
pommes normande, poulet yassa, souris d’agneau au four) sur le WordPress de
test, OpenAI gpt-5.6-luna pour le texte, gpt-image-2.5-flare pour les images,
image à la une en `low`, collage en `medium`, plafond 0,30 $ par recette.
Quatre lots ont été lancés ; les trois premiers ont révélé les défauts
corrigés ci-dessous, le quatrième est celui-ci : **3 recettes sur 3
approuvées**, article, image à la une et collage jugés *good* à chaque fois.

| Étape | Durée moyenne | Coût moyen | Estimé |
| --- | ---: | ---: | ---: |
| Recherche (web, ≤ 3 recherches) | 56 s | 0,0242 $ | 0,0369 $ |
| Recette canonique | 17 s | 0,0032 $ | 0,0040 $ |
| Article | 50 s | 0,0081 $ | 0,0087 $ |
| Image à la une (`low`) | 11 s | 0,0149 $ | 0,0149 $ |
| Collage Facebook (`medium`) | 16 s | 0,0289 $ | 0,0284 $ |
| Revue | 16 s | 0,0034 $ | 0,0043 $ |
| Vérification des faits | 32 s | 0,0060 $ | 0,0075 $ |
| Corrections (en code) | 0 s | 0 $ | 0 $ |
| Correction de la langue | 46 s | 0,0094 $ | 0,0105 $ |
| Approbation finale (1 à 4 passages) | 61 s | 0,0140 $ | 0,0061 $ |
| Collage redessiné (2 recettes sur 3) | 16 s par dessin | 0,030 $ par dessin, 0,020 $ par recette en moyenne | — |
| **Recette complète** | **244–378 s** | **0,132 $** (0,095 – 0,162) | 0,121 $, au plus 0,349 $ |

Le lot de trois a pris 14 min 18 s de bout en bout sur ce serveur, cron compris.

**Corrigé en chemin :**

- *Des recettes restaient « en cours » pour toujours.* WordPress garde tous
  ses événements cron dans une seule option ; trois recettes finissant une
  vague ensemble s’écrasaient mutuellement l’événement suivant, et deux
  recettes sur trois ne repartaient jamais. La surveillance (toutes les cinq
  minutes) relance désormais toute recette en cours qui n’est tenue par
  aucun travailleur.
- *La vérification des faits était coupée.* Son plafond de 4 000 jetons
  compte aussi la réflexion : deux vérifications sur trois s’arrêtaient au
  milieu du JSON et la recette échouait. Plafond porté à 12 000 — un
  plafond ne coûte rien tant qu’il n’est pas atteint.
- *Un article refusé pour une phrase restait refusé.* L’approbation finale
  cite la phrase fautive et donne désormais sa version corrigée (ou vide pour
  la retirer) ; le moteur l’applique en code et redemande un verdict, pour
  environ 0,005 $, au lieu de rendre un article refusé. Un refus qu’il ne
  peut pas réparer ainsi n’entraîne plus de redessiner des images inutilement.
  Les phrases corrigées restent listées pour le rédacteur. Quatre passages
  d’approbation au plus, toujours sous le plafond par recette.
- *Des explications inventées.* L’article n’attribue plus de goût, d’effet
  ou de raison qu’aucune source ne donne ; la vérification des faits retire
  celles qui passent encore.
- *L’image à la une passe par défaut en `low`* (0,015 $ au lieu de 0,021 $) :
  jugée *good* et réaliste sur les six images mesurées.

## Version 0.18.2

**Chaque image a son modèle et sa qualité.** Le sélecteur « Modèle par étape »
de l’écran Moteur propose deux lignes au lieu d’une : *Image à la une* et
*Collage Facebook*, chacune avec son modèle (tous les modèles d’image du
catalogue, relevés ou livrés) et sa qualité — pour un modèle d’image, la
qualité *est* l’effort : il n’a pas de niveau de réflexion. Le moteur lit
`routing.featured_image` et `routing.facebook_image`, et à défaut
`routing.image` ; les qualités restent `images.featured_quality` et
`images.facebook_quality`.

**L’estimation suit la qualité.** Elle comptait chaque image en `medium` quelle
que soit la qualité choisie. Mesuré en réel sur gpt-image-2.5-flare, une image
à la une 1024×1024 :

| Qualité | Jetons dessinés | Coût |
| --- | ---: | ---: |
| low | 196 | 0,0142 $ |
| medium | 439 | ≈ 0,021 $ |
| high | 1 756 | 0,0610 $ |
| xhigh | 3 122 | 0,1020 $ |
| max | 7 024 | 0,2191 $ |

Le collage suit les mêmes rapports (158, 343, 1 372 jetons). La simulation
chiffre désormais une image à la une en `high` à 0,0618 $ et un collage en
`low` à 0,0227 $ — 0,0610 $ et 0,0215 $ en réel. Vérifié en réel : image à la une
par gpt-image-2.5-flare, collage par gpt-image-2, chacun à sa qualité.

## Version 0.18.1

**Plafond par recette par défaut : 0,30 $** (au lieu de 0,50 $). Une recette
complète est estimée à 0,126 $ et coûte 0,105–0,132 $ en réel : le plafond
laisse la place d’un collage redessiné ; au-delà, le moteur s’arrête et laisse
le verdict à l’éditeur plutôt que de dépasser. Un site qui a déjà enregistré
son plafond le garde.

**Le plafond tient jusque dans les reprises.** Le budget d’une recette n’était
vérifié qu’entre deux vagues ; une approbation finale refusée pouvait
redessiner ses images et redemander, dans la même vague, au-delà d’un plafond
presque atteint. Avant chaque nouveau tour, le moteur en estime désormais le
coût — la dernière approbation plus les images à redessiner — et, s’il
franchirait le plafond, s’arrête et laisse le verdict à l’éditeur. Un test hors
ligne rejoue la boucle sans réseau : sans cette garde, cinq appels là où le
budget n’en permettait qu’un.

Suite réelle complète verte sur le WordPress de test en 0.18.1 : site, catalogue
(quatre tarifs lus sur les pages des fournisseurs) et une recette complète —
estimée 0,1266 $, facturée 0,1542 $ avec un collage redessiné une fois,
brouillon complet avec image à la une, blocs et données structurées Recipe.

## Version 0.18.0

**Environ 0,11 $ la recette complète, frais de recherche compris.** Les
0,10–0,13 $ mesurés pendant le développement du moteur ne comptaient pas les
frais de recherche web, que le moteur ignorait alors : la facture réelle
tournait autour de 0,18 $. Mesuré en réel chez OpenAI, un passage complet
coûte désormais 0,1055 $, 0,109 $ et 0,115 $ sur trois plats, chaque étape
gardant son score maximal (recherche 14/14, article 10/10, approbation 10/10).

**Ce qui a changé, et ce que chaque levier a rapporté :**

| Levier | Effet mesuré |
| --- | --- |
| Seule une *recherche* est facturée ; ouvrir une page est gratuit | le coût réel comptait 13 « recherches » là où 4 étaient facturées |
| `search_context_size: low` | recherche 0,055 $ au lieu de 0,071 $, 14/14 |
| La consigne de recherche : trois requêtes au plus, puis lire les pages | 1 recherche payante le plus souvent, 0,020–0,027 $ |
| Réflexion `low` pour la recette canonique, la rédaction et la revue | 0,1030 $ au lieu de 0,1822 $ la recette, scores intacts |
| Réflexion `medium` gardée pour la recherche, la vérification des faits, la correction et l’approbation | la recherche à `low` est revenue une fois sans aucune photographie (9/14) ; à `medium`, 14/14 sur quatre plats pour 0,022–0,032 $ |

**Deux défauts de qualité corrigés, qui coûtaient aussi.** Le collage Facebook
sautait une étape de cuisson (la précuisson de la tarte) ou montrait un
ingrédient trop tôt (des olives dans la marinade) ; l’approbation le refusait à
raison, et chaque nouveau dessin coûtait 0,03 $ de plus. L’article ajoutait des
conseils de conservation et de réchauffage non sourcés. Le prompt du collage
exige maintenant que chaque panneau montre l’état laissé par toutes les étapes
précédentes et qu’un ingrédient n’apparaisse qu’à son étape ; celui de l’article
limite ces conseils à ce que disent les sources.

**Une relecture vide effaçait l’article.** Une correction revenue sans texte
remplaçait l’article de 21 505 caractères par rien : pas de brouillon, et
l’approbation jugeait une page blanche. Une version plus récente ne remplace
plus que ce qu’elle remplit.

Vérifié ensuite sur le WordPress de test : une recette complète estimée
0,126 $, facturée 0,1318 $ avec un collage redessiné une fois, brouillon complet
(image à la une, blocs, données structurées Recipe).

**L’estimation suit.** Recherche mesurée (45 000 jetons, 2 recherches), économie
mesurée de la réflexion `low`, plafond de dix appels d’outil pour le maximum :
une recette complète est estimée à 0,126 $, au plus 0,319 $.

## Version 0.17.0

**L’estimation avant, la facture après — mesurées l’une contre l’autre.**
Premières recettes complètes réelles chez OpenAI, sur un WordPress de test :

| Lot | Estimé | Facturé |
| --- | --- | --- |
| Article seul, avant | 0,0853 $ | 0,1870 $ |
| Article seul, après | 0,1553 $ | 0,1627 $ |
| Recette complète, refusée deux fois | 0,2119 $ (un passage) | 0,2804 $ |
| Recette complète, après | 0,2132 $, au plus 0,3266 $ | 0,1860 $ |

**La recherche n’avait pas de limite chez OpenAI.** Elle a lancé 13 recherches,
facturées 0,01 $ pièce, là où l’estimation en supposait 3. Un plafond
`limits.web_searches` (10) est désormais envoyé au fournisseur —
`max_tool_calls` chez OpenAI, `max_uses` chez Claude, dont l’outil livré reste
à 3 — et l’estimation facture exactement ce plafond. Elle compte aussi la
lecture des photographies citées par la recherche.

**Deux chiffres : attendu et maximum.** L’approbation finale peut refuser et
faire redessiner les images, jusqu’à trois fois : la recette refusée deux fois
a dessiné trois collages. L’estimation donne désormais le coût d’un passage et
le maximum si chaque tentative refuse ; l’écran de lot, les réglages et la
simulation affichent les deux, et préviennent quand le maximum dépasse le
plafond par recette.

**Le coût réel compte le cache.** OpenAI facture l’entrée déjà en cache au
dixième du tarif ; elle était facturée pleine.

**Le catalogue ne garde que l’essentiel.** Par fournisseur, les deux
générations les plus récentes de chaque rôle, plus ce que le moteur utilise ou
qu’une route nomme : OpenAI passe de 132 identifiants à 12, Gemini de 59 à 5,
Anthropic de 12 à 5 — tous tarifés. GPT-6 Luna, Sol et Astra sont livrés avec
leur tarif officiel. Chez OpenAI, le tarif d’un modèle est lu directement sur
sa page — gratuit, sans IA, marqué « lu sur la page du fournisseur » — puisque
la page des tarifs ne montre que les modèles phares sans JavaScript.

**Deux défauts vus en réel.** Une recette était marquée « terminée » avant que
son brouillon existe, et un suivi lisait « brouillon #0 » ; l’avancement
comptait chaque image redessinée comme une étape (12 sur 10).

**Non vérifié** : Claude (compte sans crédit) ; la recherche web Gemini (quota
épuisé).

## Version 0.16.0

**La réflexion se règle par étape, chez les trois fournisseurs.** Un nouveau
groupe `thinking` du moteur donne un niveau — `minimal`, `low`, `medium` ou
`high` — par étape ou par défaut ; vide, le fournisseur décide. Chaque appel le
traduit dans la langue de son fournisseur : `thinkingLevel` chez Gemini,
`reasoning.effort` chez OpenAI, `effort` chez Claude (qui n’a pas de `minimal` :
`low` est le moins). Un modèle qui refuse ce réglage — Haiku 4.5, Sonnet 4.5, un
modèle OpenAI qui ne raisonne pas — n’en reçoit aucun, plutôt qu’une erreur.
Gemini reste à `low` par défaut (`providers.gemini.thinking_level`). Mesuré en
réel sur la même recette canonique : `minimal` 0,0209 $ en 12 s, `low` 0,0227 $
en 9 s, `high` 0,0649 $ en 82 s dont 4 420 jetons de réflexion — les trois 4/4.

**Partout où l’on choisit un modèle.** Le sélecteur « Modèle par étape » de
l’écran Moteur a une colonne *Réflexion*, qui écrit le champ `thinking` ; la
simulation affiche le niveau de chaque étape ; le laboratoire prend
`--thinking=`. Le sélecteur résout désormais les modèles et leurs tarifs
par-dessus le catalogue, comme le moteur, au lieu de la seule liste du moteur.

**Et dans les calculs.** Les formes d’estimation ont été mesurées à la
réflexion par défaut des fournisseurs (`medium`) : un niveau supérieur ajoute sa
réflexion à la sortie estimée de chaque étape, toujours plafonnée par le
plafond de sortie, et un niveau inférieur ne retire rien — une estimation qui lit
bas laisse passer un lot au-delà de son plafond. La réflexion est comptée à part
dans le journal de chaque appel (« 6 579 out (4 420 thinking) »).

**Toujours non vérifié** : un lot complet (recherche web épuisée sur la clé
Gemini, OpenAI bloqué par la politique réseau de l’environnement de test, compte
Claude sans crédit).

## Version 0.15.0

**Un tarif relevé ou corrigé n’atteignait plus le moteur après un seul
enregistrement de l’écran Moteur.** Le formulaire affiche les tarifs du
catalogue ; l’enregistrer sans rien toucher les stockait comme saisis à la main,
parce que le JSON réécrit 4.0 en 4 et que la comparaison était stricte. Ce
groupe figé remplaçait ensuite tout le catalogue : chaque tarif relevé, cherché
ou corrigé sur l’écran Modèles restait sans effet. Reproduit en réel. La
comparaison se fait désormais en valeur et contre le catalogue ; un tarif saisi
ne masque plus que son propre modèle ; et la migration retire les copies déjà
figées.

**Un modèle désactivé perdait son tarif.** Seuls les modèles activés
transmettaient leur tarif au moteur, si bien qu’une route nommant
`gemini-3.6-flash` — livré tarifé mais désactivé — était « sans tarif » et
arrêtait le run. Activer ne décide plus que des paliers ; tout tarif connu est
transmis.

**Les tarifs suivent le relevé.** Après « Relever les modèles », la recherche de
tarifs part aussitôt pour les seuls modèles qui n’en ont pas (rien n’est
dépensé s’il n’en manque aucun). GPT-5, GPT-5 mini, GPT Image 1 et Gemini 3
Flash Preview sont livrés tarifés, et une ligne restée sans tarif est remplie
dès qu’un tarif est livré. Les réponses affichées sont échappées.

**La simulation de l’écran Moteur chiffre.** Elle résout ce qui est à l’écran
par-dessus le catalogue — et non plus contre la seule liste du moteur, où tout
modèle du catalogue paraissait sans tarif —, route les images comme le moteur,
dit si la clé existe vraiment, et donne le coût de chaque étape et d’une recette
complète.

**Gemini ne pense plus jusqu’au plafond.** Gemini compte sa réflexion dans le
plafond de sortie : une recette canonique réelle sur `gemini-3.5-flash` a pensé
jusqu’à 4 500 jetons, s’est arrêtée sur `MAX_TOKENS` avec un JSON coupé, et a
été facturée 0,046 $ pour rien. Le moteur demande désormais une réflexion
`low` (`providers.gemini.thinking`) : la même étape, en réel, passe 4/4 en
8,8 s pour 0,0227 $. Une réponse coupée est signalée d’après ce que le
fournisseur dit, et plus seulement d’après le compte de jetons. Changement du
moteur consigné dans [`.claude/docs/ENGINE.md`](.claude/docs/ENGINE.md) §7.

**Vérifié en réel** : recette canonique (4/4, 0,0227 $) et article (8/10,
0,0556 $, estimé 0,0698 $) sur Gemini ; simulation, relevé et recherche de
tarifs sur un WordPress 6.8.3. **Non vérifié** : un lot complet — la recherche
exige une recherche web, épuisée sur la clé Gemini ; OpenAI reste injoignable
depuis l’environnement de test malgré l’accès annoncé, et le compte Claude n’a
pas de crédit.

## Version 0.14.0

**Les jetons de réflexion de Gemini n’étaient pas comptés.** Google facture la
réflexion comme de la sortie mais la déclare à part (`thoughtsTokenCount`), et
le moteur ne lisait que `candidatesTokenCount`. Mesuré en réel sur
`gemini-3.6-flash` : 5 jetons visibles, 2 717 de réflexion — l’appel était
compté à 1/500ᵉ de son prix. Même oubli pour ce qu’un outil lit
(`toolUsePromptTokenCount`, 8 973 jetons pour une page de tarifs), pour les
lectures et écritures de cache de Claude, et pour les recherches web, facturées
à la requête par les trois fournisseurs (0,01 $ ; 0,014 $ chez Google). Le
moteur les compte désormais ; l’estimation de la recherche inclut ses trois
recherches. Changement du moteur fait à la demande du propriétaire, consigné
dans [`.claude/docs/ENGINE.md`](.claude/docs/ENGINE.md) §7.

**`medium` coûtait dix fois trop cher sur un site neuf.** Les paliers étaient
reconstruits en triant par prix les modèles « capables d’écrire », mais les
modèles venus de la liste du moteur (`gpt-5-nano`, `gpt-5.4-mini`) n’étaient
marqués capables de rien : le milieu de la liste OpenAI devenait Terra
(2 $/12 $) au lieu de Luna (0,20 $/1,20 $), et une recette complète était
estimée — et dépensée — à 0,69 $ au lieu de 0,11 $. Le choix du moteur
s’applique maintenant dès que le site peut l’utiliser ; le tri par prix ne sert
plus que de repli. Les lignes sans capacité déclarée sont classées à la
migration.

**Le catalogue ne garde que les modèles utiles.** Gemini liste 59 modèles,
dont la synthèse vocale, la musique, la vidéo, les embeddings, la robotique et
l’audio en direct, tous annoncés comme capables de « générer du contenu ». Il en
reste 8 : les modèles de conversation 3.x. Chez OpenAI, la famille GPT-5 et les
modèles d’image ; chez Anthropic, les douze modèles actuels. Les instantanés
datés, les alias `-latest`, les préversions doublées d’une version stable et la
génération 2.5 (refusée aux nouveaux comptes par un 404) sont écartés. Les
lignes parasites déjà enregistrées sont supprimées à la migration — jamais un
tarif saisi à la main, jamais un modèle affecté à une étape. Les modèles
d’image Gemini sont écartés : le moteur ne dessine qu’avec OpenAI.

**Les tarifs livrés sont à jour, et le restent.** Gemini 3.5 Flash-Lite,
3.6, 3.7 et 3.8 Flash, et tous les modèles Claude actuels sont livrés avec leur
tarif et leur page source, désactivés pour ne pas déplacer les paliers. Un tarif
livré par une version précédente est désormais mis à jour par la suivante ; un
tarif saisi ou recherché ne l’est jamais.

**La recherche de tarifs aboutit.** Une question par fournisseur, qui nomme sa
page de tarifs ; Gemini l’ouvre avec `url_context` au lieu de chercher (la
recherche Google était épuisée sur la clé de test, la lecture de page non).
Quand un modèle refuse — quota, crédit, « forte demande », les trois vus en
réel — le suivant est essayé. Une source qui n’est pas la page du fournisseur
est refusée.

**Vérifié en réel** sur un WordPress 6.8.3 (SQLite) : liste des modèles, tri,
nettoyage et reprise des tarifs à la migration, recherche de tarifs sur Gemini
(quatre tarifs trouvés, conformes à la page de Google). **Non vérifié** : une
génération complète — OpenAI est injoignable depuis l’environnement de test et
le compte Claude n’a plus de crédit.

## Version 0.13.0

**Le moteur parle enfin les trois langues.** Cinq corrections, proposées dans
[`.claude/docs/ENGINE.md`](.claude/docs/ENGINE.md) §7 et approuvées par le
propriétaire. Le gabarit d’article exigeait « é, è, ê, à… » et rejetait un
texte sans accents, quelle que soit la langue qu’il venait de recevoir. Le
contrôle des sections obligatoires ne connaissait que des intitulés français :
un article anglais correct échouait à chaque fois — 9/10 en conditions réelles,
« missing: choix, matériel, erreurs, conservation » — et payait une reprise
incapable de corriger quoi que ce soit. La clé `language` de premier niveau
était enregistrée à chaque run, affichée sur l’écran Moteur, et lue par
personne. Le contrôle de longueur ne regardait que le minimum : un article
anglais réel est revenu à 5 988 mots pour une cible de 2 800–3 600, et un
article trop long est payé deux fois de plus, puisque la revue et la relecture
le lisent en entier. Et `claude:low` nommait `claude-haiku-4-5`, qu’Anthropic
ne sert pas.

**Un audit de sécurité, et ce qu’il a trouvé.** `sanitize()` masquait par nom
de clé — ce qui attrape une charge utile construite ici, mais pas ce que le
fournisseur répond : OpenAI refuse une mauvaise clé par « Incorrect API key
provided: sk-proj-… », et cette phrase était enregistrée telle quelle comme
erreur d’étape puis affichée sur deux écrans. Toute chaîne ayant la forme d’une
clé est désormais masquée, où qu’elle se trouve, y compris imbriquée — et la
prose ordinaire est conservée mot pour mot, car une erreur trop caviardée ne
sert plus à rien.

**Et ce qu’il a trouvé côté écrans.** Deux requêtes média utilisaient
`max-inline-size`, qui n’est pas une caractéristique média reconnue : elles
étaient mortes en silence. Une fois vivantes, l’écran Moteur débordait encore
de 206 px à 390 px de large. Les tableaux larges tiennent maintenant dans la
région défilante que l’extension possédait déjà — focalisable et nommée, pour
qui ne peut pas faire glisser. Débordement nul sur les huit écrans, aux deux
largeurs, pour les deux rôles ; et le rédacteur ne rencontre toujours aucun
montant, aucun nom de modèle, aucun compte de jetons.

**Les listes imbriquées sont devenues des blocs.** Une liste dans une liste
était écrite en HTML brut dans l’élément : rien n’était perdu et l’éditeur ne
signalait rien, mais elle n’était pas un bloc — impossible de l’indenter, de la
réordonner, de lui ajouter une ligne. WordPress imbrique un bloc de liste dans
l’élément ; c’est ce qui est fait, vérifié dans l’éditeur.

**Du code mort en moins, et une vraie lacune qu’il cachait.** Six méthodes que
rien n’appelait, trouvées par graphe d’appels. `MSRWA_I18N::load()` en faisait
partie — sauf que c’est elle qui charge les catalogues. Rien ne l’appelait : les
traductions n’apparaissaient que parce que WordPress 6.7 s’est mis à charger le
dossier `/languages` d’une extension à la demande. Invisible sur un site à
jour, total sur un site plus ancien, où chaque écran serait resté français quel
que soit le choix du lecteur. Elle est accrochée à `init`. Huit entrées de
catalogue devenues orphelines sont parties : 553 chaînes, 553 traduites dans
les deux langues.

## Version 0.12.0

**Le moteur ne contient plus que le processus éditorial.** Quels modèles
existent, ce qu’ils coûtent et quelle étape chacun a le droit de servir ne sont
pas du processus : cela change quand un fournisseur renomme un modèle ou
déplace un tarif, et ni l’un ni l’autre ne devrait obliger à toucher la chaîne
qui écrit un article. Tout cela passe dans une table à part, et le moteur
reçoit des `models` et des `tiers` engendrés, par la couche appelante qu’il
acceptait déjà. **Aucune modification du moteur n’a été nécessaire** : les deux
groupes étaient déjà surchargeables. Ce qui est saisi à la main l’emporte
toujours — qui édite `tiers` lui-même sait ce qu’il fait.

**Une page Modèles, avec deux boutons qui ne se ressemblent pas.** Demander à
un fournisseur la liste de ce qu’il sert est gratuit et certain : c’est une
action. Demander à un modèle de lire des pages de tarifs coûte quelques
centimes et peut se tromper : c’en est une autre, et ce qu’elle rapporte est
marqué « trouvé par IA », cite la page lue, et ne remplace jamais un tarif
saisi à la main. Un nombre faux qui a l’air sûr est pire qu’une case vide : la
case vide arrête l’estimation, le nombre faux se facture en silence. Ce qui
revient est donc refusé s’il n’est pas dans l’ordre de grandeur d’un vrai
tarif, ou s’il ne cite aucune page.

**Aucun fournisseur ne publie ses tarifs par API.** Vérifié sur les réponses
réelles des trois : pas un champ de prix. En revanche Gemini donne ses limites
de jetons et les méthodes qu’un modèle supporte, et Anthropic déclare ses
capacités — `image_input`, `structured_outputs` — donc tout cela est relevé.
Une capacité qu’un fournisseur ne mentionne pas est laissée telle quelle et non
mise à faux : un silence n’est pas un refus, et écraser un drapeau correct
retirerait du service un modèle qui marche.

**La grille modèle × étape.** Chaque modèle, chaque étape, à cocher. Rien de
coché veut dire aucune restriction, pour qu’une installation neuve tourne. Et
la grille n’est pas décorative : le Diagnostic prévient quand une étape tourne
sur un modèle auquel elle n’a pas été autorisée.

**Ce que cela a corrigé.** `claude:low` désignait `claude-haiku-4-5`, qu’Anthropic
ne sert pas. Les niveaux sont désormais engendrés — le moins cher, le milieu et
le plus cher de ce que le site a vraiment — donc il désigne
`claude-haiku-4-5-20251001`, qui existe. Corrigé comme une donnée, sans toucher
au moteur. Les engendrer au prix seul était faux et le premier relevé réel l’a
montré : cela plaçait `gpt-image-2.5-sunburst` derrière l’étape Article. Un
niveau est une route de texte ; il faut donc savoir écrire pour en être un, et
une capacité non déclarée n’est pas supposée présente.

**Et les deux listes de tarifs n’en font plus qu’une.** Le moteur en tarifait
dix-neuf, le plugin neuf, sous des identifiants différents pour le même modèle.
Seule celle du moteur était facturée. C’est la table, maintenant, et elle est
semée des deux.

## Version 0.11.0

**L’écran Moteur dit enfin quel modèle va tourner.** « Modèle par étape »
proposait un fournisseur et un niveau — *low*, *medium*, *high* — et s’arrêtait
là. Or ce n’est pas une réponse à « qu’est-ce qui va tourner et combien cela
coûtera » : c’est la table `tiers` du moteur qui transforme ce couple en un
identifiant de modèle, et cet identifiant n’apparaissait nulle part. Une
troisième colonne le montre désormais, avec son tarif, et prévient quand il
n’en a pas.

**Ce que cela a immédiatement révélé.** `claude:low` nomme
`claude-haiku-4-5`, alors qu’Anthropic sert `claude-haiku-4-5-20251001` :
toute étape routée là échoue, après avoir payé toutes celles d’avant. Et il
existe deux listes de tarifs qui divergent — celle du moteur, sur laquelle il
facture, et celle du plugin — ce qui est exactement la manière dont une
estimation cesse un jour de correspondre à une facture. La table `tiers`
appartient au moteur, donc rien n’y a été touché ; les deux constats sont
relevés dans [`.claude/docs/ENGINE.md`](.claude/docs/ENGINE.md) §7.

**La liste des modèles du fournisseur ne part plus à la poubelle.** La
vérification des clés interroge `/models` chez chacun — c’est gratuit et c’est
ce qui prouve que la clé ouvre la porte — puis ne lisait que le code HTTP et
jetait la réponse. C’est pourtant la seule chose que le fournisseur sait et que
ce plugin ne peut pas deviner : quels identifiants répondent encore, ce qui
change sans prévenir quand un modèle est renommé ou retiré. La liste est
maintenant conservée, datée, affichée sous « Modèles et tarifs », et le
Diagnostic refuse de dire le routage vert quand une étape nomme un modèle que
son fournisseur ne sert pas.

**Deux sections de référence sur l’écran Moteur.** Ce que fait le moteur étape
par étape — ce que chaque étape attend, ce qu’elle produit, sur quel modèle
elle tourne — et le catalogue des modèles avec leurs tarifs, leurs capacités et
leur état chez le fournisseur. Le registre n’était lisible que sous forme de
JSON brut dans une case vide sur un site qui n’a rien modifié : le seul écran
consacré au moteur ne disait rien de ce que le moteur fait.

**Du code mort en moins.** `MSRWA_Catalog` gardait un installateur, un filtre
d’éligibilité et un magasin d’état qui interrogeaient les tables
`msrwa_providers` et `msrwa_models` — supersédées et plus jamais créées. La
classe passe de 207 à un peu plus de 100 lignes et ne contient plus que ce qui
s’exécute.

**Plus de thème sombre.** L’interface est claire, par décision du propriétaire.
Les couleurs restent des jetons, donc un thème pourrait revenir en un bloc,
mais plus rien ne suit la préférence système du lecteur. Une nouvelle suite
(`tests/test-styles.php`) tient les trois promesses que l’en-tête de la feuille
de style énonçait sans que rien ne les vérifie : clair uniquement, aucune
propriété physique gauche/droite — l’interface est aussi arabe — et aucun appel
à un autre serveur depuis l’administration de quelqu’un d’autre.

## Version 0.10.0

**L’article arrive en blocs, pas en un mur de HTML.** Le moteur rend une seule
chaîne de HTML sémantique ; elle était écrite telle quelle dans le brouillon,
et l’éditeur de blocs n’en faisait qu’une boîte classique : impossible de
déplacer un paragraphe, d’ajouter une image au milieu, ou d’utiliser un seul
des outils qu’un relecteur a sous la main. `MSRWA_Blocks` convertit les titres,
les paragraphes, les listes — chaque élément de liste étant lui-même un bloc,
comme WordPress l’exige depuis la 6.0 — et la coupure de page. Ce que le
contrat n’avait pas promis est enveloppé dans un bloc HTML plutôt que perdu :
perdre un paragraphe est bien pire que l’afficher dans un bloc plus simple.
Vérifié dans le vrai éditeur : quatorze blocs, aucun invalide, aucune boîte
classique, aucun avertissement de contenu inattendu.

**La description SEO et l’aperçu de partage sont enfin publiés.** L’étape
article écrivait un titre SEO, une description SEO et une légende Facebook, et
le moteur dessinait une image de partage en 1200 × 630. Les quatre étaient
rangés sur l’article et lus par personne : sans Yoast ni Rank Math installé, la
description n’atteignait aucune balise et le lien partagé retombait sur ce que
le thème voulait bien donner. `MSRWA_Head` les imprime — description, Open
Graph, carte X, avec l’image de partage quand elle existe et l’image à la une
sinon — et s’efface dès qu’une extension SEO est active, pour la même raison
que le JSON-LD Recipe : deux descriptions d’une même page valent moins qu’une.
Sur les articles publiés par cette extension seulement, jamais sur ceux du
site.

**« Clé acceptée » ne veut plus dire « le compte peut payer ».** La
vérification des clés liste les modèles du fournisseur : c’est gratuit, cela
prouve que la clé est bonne et que le serveur sort — et un compte à zéro liste
ses modèles avec le même entrain. Elle annonçait donc vert un compte incapable
de payer un seul mot, et l’exploitant l’apprenait quand un lot mourait à sa
première étape. Chaque appel réel étant déjà consigné, le dernier refus est
relu : crédit épuisé ou quota atteint, la nuance décidant s’il faut attendre ou
recharger. L’écran Diagnostic le dit en rouge, avec le fournisseur nommé et le
geste à faire, et la phrase que lit un rédacteur sur une recette arrêtée vient
désormais de la même lecture — les deux ne peuvent plus se contredire.

**Ce que vérifient les tests réels.** `tests/real/test-flow.php` ne s’arrête
plus au fait qu’un brouillon existe : il exige des blocs appariés, l’image à la
une attachée au bon article avec son texte alternatif et ses tailles générées,
puis publie l’article le temps de lire sa page — description, Open Graph, carte
X, JSON-LD Recipe — et le repasse aussitôt en brouillon. Un article non relu ne
reste jamais publié : c’est précisément ce que cette extension existe pour
empêcher.

## Version 0.9.0

**Le verdict du juge arrive enfin dans l’éditeur que les sites utilisent.** Il
voyageait avec le brouillon jusqu’à une boîte classique, et l’éditeur de blocs
replie ces boîtes derrière un tiroir fermé au bas de l’écran — la boîte était
bien dans la page, dans un conteneur en `display: none`, derrière une barre de
32 pixels que personne n’a de raison d’ouvrir. La seule chose que cette
extension promet de mettre sous les yeux d’un relecteur avant publication
n’était donc, sur la plupart des sites, sous les yeux de personne.
`assets/editor.js` enregistre le même verdict à deux endroits : un panneau dans
la colonne de l’article, et le contrôle d’avant-publication, qui s’ouvre de
lui-même quand une remarque est bloquante — le moment où la question se pose
vraiment. La boîte classique reste pour l’éditeur classique, et les deux lisent
désormais le même `MSRWA_Editor::verdict()`.

**Un écran Diagnostic.** Huit contrôles, dans l’ordre où une recette les
rencontre : tables et schéma, clés, routage, file et cron, téléversements,
droits, plafonds, tables laissées par une version précédente. Chacun dit ce qui
a été mesuré et, quand ce n’est pas vert, quoi faire. Dessous, les mêmes
constats en un bloc à copier pour une demande d’aide, sans clé, sans chemin et
sans adresse du site. Rien n’y appelle un fournisseur : l’écran est consultable
pendant qu’un lot tourne.

**Le menu dit combien de brouillons attendent.** Dans la pastille que
WordPress utilise déjà pour les commentaires et les mises à jour, et avec la
même portée que partout ailleurs : un rédacteur voit les siens, un
administrateur voit tout. Un lot parti le soir n’attend plus que quelqu’un
pense à ouvrir le pass.

**Une limite de débit n’est plus annoncée comme un compte vide.** Google
répond à une requête freinée par « You exceeded your current quota, please
check your plan and billing details » — une phrase qui contient *billing* et
*quota* alors que le compte est approvisionné. `MSRWA_UI::reason()` en
concluait qu’il fallait recharger, et la branche qui dit « réessayez plus
tard » était inatteignable pour ce fournisseur. Elle ne reconnaît plus comme
compte vide que les formulations qui le sont sans ambiguïté.

**Les messages d’erreur sont traduits.** Les vingt-deux `WP_Error` que l’API
REST renvoie — et que le script affiche tels quels — étaient des littéraux
français, invisibles pour l’extracteur puisque rien ne les enveloppait. Un
rédacteur anglophone lisait « Aucune recette dans ce qui a été fourni. » Tous
passent par `__()`, et un test refuse désormais un `WP_Error` construit avec un
littéral.

**Rangement.** Tous les documents passent sous `.claude/docs/`, la racine ne
garde que ce `README.md` et `CLAUDE.md`, et `tests/test-docs.php` vérifie que
chaque lien relatif de chaque document mène quelque part. Le README perd ses
deux dernières sections, qui décrivaient des écrans disparus depuis la 0.3.0.
`MSRWA_Estimate` et `MSRWA_Batch` cessent de porter chacun une copie identique
de la même fusion récursive de configuration, et quatre règles CSS que rien
n’émettait sont retirées.

## Version 0.8.2

**Un modèle généré pouvait écrire dans la fiche recette d’une extension tierce
sans y être lu.** Chaque champ que `MSRWA_Draft` écrit passe par
`wp_strip_all_tags()` avant d’être stocké — sauf, jusqu’ici, ceux que
`map_recipe()` transmet aux clés que le réglage *mapping* choisit pour la
fiche recette d’un site : le titre et la description SEO y arrivaient tels
quels, de même que la description, les notes et les instructions de la
recette elle-même. Une extension de fiche recette qui n’échappe pas ce qu’elle
affiche transformerait une réponse du modèle en script exécuté pour chaque
visiteur. Ces champs sont désormais nettoyés au même titre que tout le reste,
et le titre et la description SEO le sont deux fois plutôt qu’une : une fois
dans `describe()`, une fois dans `map_recipe()` lui-même, pour que la fonction
reste sûre même appelée autrement demain. Trouvé par une revue de sécurité de
la branche, vérifié par un nouveau test qui fait écrire au modèle un
`<script>` et confirme qu’aucune des cinq clés mappées ne le porte.

**Vérifié dans un vrai navigateur, comme administrateur et comme éditeur.**
Dix-neuf vérifications automatisées contre le site local : réglages
enregistrés et relus, vérification des clés en direct, estimation en direct
sur l’écran de dépôt, création puis envoi réel d’un lot, écrans Analyse et
Pass, purge de rétention — puis, avec un compte éditeur distinct : aucun
montant sur aucun écran, aucun lien vers Réglages, Moteur ou Analyse, refus au
niveau de WordPress lui-même sur les trois (403, avant que l’écran ne soit
seulement atteint), aucun champ de plafond sur le dépôt.

**Où en sont les trois fournisseurs, pour ce lancement.** Aucun des trois ne
peut aujourd’hui mener une recette jusqu’au bout depuis cette machine :
OpenAI reste injoignable au niveau réseau ; la clé Claude répond désormais
« solde insuffisant » à chaque appel ; la clé Gemini écrit du texte sans
problème mais son outil de recherche web — que l’étape recherche exige —
répond `429` ou `503` à chaque tentative, ce qui ressemble à un palier gratuit
trop bas pour la recherche web plutôt qu’à une clé invalide. Détaillé dans
[`PLAN.md`](.claude/docs/PLAN.md), « Ce dont j’ai besoin de vous ».

## Version 0.8.1

**Un modèle par étape, choisi plutôt qu’écrit.** L’écran *Moteur* offrait un
seul moyen de changer quel fournisseur sert une étape : éditer à la main le
groupe JSON `routing`. Il porte maintenant un sélecteur fournisseur et niveau
pour chacune des neuf routes que le moteur consulte réellement — y compris les
deux clés partagées par plusieurs étapes (`image` pour l’image à la une et le
collage Facebook, `vision` pour la lecture des photographies) — et réécrit le
même champ JSON à chaque changement ; ce qui y est modifié à la main reste
possible et prévaut en cas de désaccord après un chargement de page. Vérifié en
réel : changer la route de recherche vers Claude, à haute qualité, produit
`"research": "claude:high"` dans le champ, sans rien enregistrer ni dépenser.

**Prévisualiser sans enregistrer.** Un bouton résout tout le formulaire — y
compris un champ JSON tout juste modifié — à travers l’API que l’écran
possédait déjà sans jamais l’appeler (`/diagnostics/config`) : quelle route
chaque étape prendrait, si une clé existe pour elle, si son tarif est publié.
En l’appelant pour la première fois, la prévisualisation lisait le dossier des
prompts comme si c’était un fichier pour l’étape « corrections », qui n’en a
pas — elle applique les corrections factuelles en code, sans jamais appeler de
modèle. Elle est maintenant exclue de ce que la prévisualisation résout, comme
elle l’est déjà de l’estimation. Une route mal orthographiée se découvre ainsi
avant l’enregistrement, jamais
en pleine recette.

## Version 0.8.0

**Premier passage complet sur un vrai WordPress, et ce qu’il a montré.** Un
WordPress 7.1.1 installé pour de vrai, l’extension activée, des lots lancés par
l’API REST avec un mot de passe d’application et menés jusqu’au brouillon par le
cron. Deux recettes réelles, une en français et une en anglais, chacune pour
environ 0,25 $ et cinq minutes — l’estimation annonçait 0,2485 $.

**Une clé Claude enregistrée n’atteignait jamais le moteur.** Le plugin la
transmettait sous le nom `anthropic` ; le moteur appelle ce fournisseur
`claude` et la cherchait sous ce nom. Toute étape routée vers Claude échouait
sur « No API key for claude » pendant que l’écran affichait « clé
enregistrée ».

**Les réglages du site n’atteignaient pas le moteur non plus.** Le moteur prend
une branche `settings` non vide pour l’ensemble complet des réglages de
l’appelant ; le plugin ne lui donnait que les clés. Nombre de mots minimum,
titres, ingrédients, étapes : chaque seuil se comparait à zéro. La coupure en
deux pages ne partait jamais. Le moteur reçoit désormais tous les réglages du
site avec les clés.

**La langue d’un lot était ignorée.** Elle partait dans une clé du moteur que
rien ne lit ; les prompts lisent `site_language`. Un lot demandé en anglais
était écrit en français. Elle arrive désormais là où les prompts la lisent,
l’arabe est une langue que les prompts savent nommer, le contrôle des accents
français s’efface pour l’anglais et l’arabe, et le titre qui ouvre la seconde
page est écrit dans la langue de l’article. Vérifié en réel : une shakshuka
écrite en anglais, coupée en deux pages.

**Un lot qui ne peut pas finir sous son plafond est refusé, gratuitement.** Le
plafond par recette arrête un run avant l’étape qui le dépasserait : une
recette estimée au-dessus s’arrêtait donc en route après avoir payé tout ce qui
précédait. Elle est maintenant refusée au lancement, et l’écran de dépôt le dit
avant qu’on appuie. Le plafond livré passe de 0,10 $ à 0,50 $ : l’ancien était
inférieur au prix d’une recette complète, si bien qu’un rédacteur ne pouvait
lancer aucun lot complet sur une installation neuve.

**Le rédacteur ne voit plus aucun montant** (jalon 5). Le pass lui montrait la
dépense du site et le coût moyen, le dépôt un prix par sortie, et l’API
d’estimation lui renvoyait les chiffres. Il apprend maintenant seulement si le
lot tient sous le plafond. La page d’une recette lui dit où elle en est en une
phrase — « le brouillon est prêt », « le site n’est relié à aucun service
d’écriture pour une étape » — au lieu d’un message technique, et liste les
étapes par leur nom, sans score ni nom de contrôle.

**Le brouillon porte ce que l’article a écrit sur lui-même.** La relecture ne
rend que le corps du texte ; le brouillon prenait son artefact tel quel et
perdait l’extrait, le slug, le titre et la description SEO, les étiquettes, la
légende Facebook. Tout arrive maintenant sur l’article : les métadonnées SEO
dans Yoast ou Rank Math quand l’un d’eux est installé, les champs de la recette
sous les clés que lit la fiche recette du site, et une boîte « Référencement et
réseaux sociaux » sur l’écran d’édition.

**Données structurées Recipe.** Une fois l’article publié, sa recette est
imprimée en JSON-LD schema.org — temps en ISO 8601, ingrédients, étapes,
portions, calories — sans extension de recettes. Rien n’est ajouté quand une
extension de recettes imprime déjà les siennes.

**Réglages des articles.** Langue par défaut des articles, longueur minimale et
maximale, coupure en deux pages et titre de la seconde page se règlent enfin à
l’écran ; ces champs étaient remis à leur valeur livrée à chaque
enregistrement.

**Vérifier les clés.** Un bouton demande à chaque fournisseur la liste de ses
modèles — gratuit — et dit pour chacun : acceptée, refusée, ou injoignable
depuis ce serveur.

**Un compte fournisseur à court de crédit est dit comme tel**, au lieu d’une
recette « arrêtée à l’étape Recette de référence ».

**Les droits sont accordés depuis une seule liste**, à l’activation comme à
chaque mise à jour. La mise à jour appliquait une autre liste : un éditeur
perdait `msrwa_view_all` à la mise à jour et le retrouvait à l’activation
suivante.

**Ménage.** Vingt réglages que plus rien ne lisait depuis la réécriture de la
0.3.0, quatre méthodes mortes de `MSRWA_Recipe`, et `assets/operations.css`,
chargée par personne, sont retirés.

**Les tests réels passent sans permaliens jolis** (`?rest_route=`), vérifient
qu’un lot au-dessus de son plafond est refusé sans rien dépenser, que les clés
ouvrent leur fournisseur, et que le brouillon porte extrait, slug et
étiquettes.

**En attente de votre accord — le moteur n’a pas été modifié.** Quatre
changements sont proposés dans [`.claude/docs/ENGINE.md`](.claude/docs/ENGINE.md), § 7.

## Version 0.7.8

**L'estimation en direct ne marchait sur aucun site aux permaliens simples.**

Sans permaliens jolis, WordPress sert son API sous
`?rest_route=/msrwa/v1`. Le script collait `'/estimate?profile=…'` au bout :
le `?` tombait donc à l'intérieur de la valeur de `rest_route`, qui devenait
`/msrwa/v1/estimate?profile=full`, une route qui n'existe pas. 404, silence, et
le seul chiffre que l'écran de dépôt affiche avant de dépenser quoi que ce soit
restait vide.

Les paramètres sont désormais assemblés à un seul endroit, qui choisit le
séparateur selon ce que la base laisse disponible. Un test refuse tout appel
qui recollerait une chaîne de requête sur son chemin.

Trouvé en cliquant dans l'installation locale, pas dans la suite : c'est
précisément ce qu'un test hors ligne ne peut pas voir.

## Version 0.7.7

**Le plafond par recette n'était pas un plafond.**

`create()` calculait bien le plafond à retenir — celui du site si la personne
n'a pas le droit de le fixer — puis passait à la place celui envoyé par le
navigateur. Le calcul ne servait à rien. N'importe qui pouvant déposer un lot
pouvait donc annoncer son propre plafond par recette.

Le formulaire y participait : il affichait le champ à tout le monde, avec
`0.20` écrit en dur dans le gabarit, alors qu'un rédacteur ne voit jamais un
montant nulle part ailleurs dans le plugin. Le champ n'apparaît plus que pour
qui a le droit de le régler, et la valeur proposée vient des réglages.

Où l'on ajoute enfin le troisième plafond à l'écran qui en annonçait trois et
n'en montrait que deux : « par recette » se règle à côté de celui du jour et de
celui des trente jours, et c'est cette valeur-là qui s'applique à un lot déposé
par quelqu'un qui ne voit pas les montants.

## Version 0.7.6

**Un lot auquel on renonce peut enfin être jeté.**

La route existait depuis longtemps ; aucun bouton ne l'appelait. Un lot décidé
contre restait donc sur le pass, à attendre une confirmation qui n'arriverait
jamais — ce qui était supportable tant que rien ne le listait, et ne l'est plus
depuis 0.7.5.

Réservé aux administrateurs, et refusé tant que des recettes tournent : on ne
supprime pas un lot sous les pieds de ses propres runs. Les brouillons déjà
produits sont des articles comme les autres et ne bougent pas. Le bouton est
rouge, parce qu'un bouton qui détruit quelque chose doit le dire avant qu'on
appuie, pas seulement dans la boîte de dialogue.

## Version 0.7.5

**Un lot qui n'est pas encore parti existe enfin quelque part.**

Jusqu'ici, un lot quittait l'écran en même temps que la personne qui l'avait
fait. Programmé pour neuf heures demain, ou apparié jeudi dernier et jamais
envoyé, il n'existait que dans la base — c'est-à-dire nulle part, pour celui
qui l'attendait. Aucun écran ne listait les lots : on n'y arrivait que depuis
une recette déjà lancée.

Le pass ouvre donc sur ce qui n'est pas encore parti : l'heure d'envoi quand il
y en a une, « attend votre confirmation » quand il n'y en a pas, et le plus
proche en premier, parce qu'un lot avec une heure dessus est un lot avec une
échéance. La section se tait quand il n'y a rien — c'est le seul écran fait
pour être lu d'un coup d'œil.

## Version 0.7.4

**Les durées de conservation se règlent depuis l'écran, et le ménage se
déclenche à la main.**

Les trois durées étaient des filtres PHP. Dire à un propriétaire de site
d'écrire `add_filter( 'msrwa_retention_events_days', … )` dans un fichier,
c'est ne pas lui donner le réglage. Ce sont maintenant des réglages, avec leur
propre formulaire, et le filtre existe toujours et garde le dernier mot — quand
un filtre impose autre chose que ce qui est saisi, l'écran le dit, au lieu de
montrer un chiffre qui ne s'appliquera pas.

Chaque passage est écrit noir sur blanc, même celui qui n'a rien trouvé à
retirer : « passé il y a une heure, rien à faire » et « n'a pas tourné depuis
mars » se ressemblaient beaucoup trop. Et on peut en déclencher un soi-même,
borné comme celui du cron : un site avec un an de retard se nettoie en
plusieurs fois, pas en une requête que l'hébergeur tue.

Au passage, la désinstallation laissait derrière elle `msrwa_queue_held`, la
capacité `msrwa_view_own` et tout ce qui avait été donné au rôle `writer`. Un
nouveau test lit les options, les capacités et les rôles directement dans le
code et vérifie que `uninstall.php` les rend tous — une liste tenue à la main
finit toujours par prendre du retard sur la version suivante.

## Version 0.7.3

**La priorité se voit, et se règle là où on la regarde.**

Faire passer une recette devant les autres existait depuis 0.7.0, mais en
action groupée seulement, et rien n'en montrait le résultat : on cliquait dans
le vide. La priorité apparaît maintenant sur le ticket de la recette, tant
qu'elle attend, et le bouton est sur la page de la recette elle-même — celle
qu'on regarde quand on se demande pourquoi celle-ci n'est toujours pas partie.

Il n'y est que pendant l'attente. Réordonner une recette qui tourne déjà ne
change rien, et le proposer laisserait croire le contraire.

## Version 0.7.2

**Quatre défauts visuels, dont deux ne se voyaient que sur un téléphone.**

Un tableau large était écrasé jusqu'à ce qu'un nom de modèle se casse en trois
morceaux au milieu d'un trait d'union. Dans une zone qui défile, c'est
désormais le contenu qui décide de la largeur : une ligne par ligne, et on fait
défiler. Les deux dégradés qui suivent le contenu et les deux qui ne bougent
pas laissent une ombre du côté où il reste quelque chose à voir — sans quoi
rien n'indique qu'il y a une suite. Ces zones sont aussi atteignables au
clavier et nommées : une région qui défile à la souris mais pas au clavier est
un tableau dont certains lecteurs ne verront jamais la fin.

La grille de chiffres peignait ses propres filets. Avec cinq tuiles sur deux
colonnes, la case vide se lisait comme un bloc de couleur. Les filets
appartiennent maintenant aux tuiles, et la case qui reste est simplement vide.

Le bouton d'enregistrement flottait entre deux cartes, alors que partout
ailleurs dans le plugin un bouton qui termine un formulaire est dans une
carte. Il y est.

Et le nouveau tableau des postes tient sur un téléphone : la barre disparaît
sous 782 px, parce que la part est écrite à côté d'elle et que la largeur va au
texte.

## Version 0.7.1

**Deux questions que le tableau par étape ne répondait pas.**

*Où part l'argent* : les quatre postes du moteur — l'article, l'image à la une,
l'image Facebook, le reste — avec la dépense et le temps passé côte à côte. Le
tableau par étape est une longue liste ; ceci tient en quatre lignes, et c'est
ce qu'on regarde en premier devant une facture. Le temps y est distinct de la
dépense, parce qu'il l'est : une image coûte cher et va vite, une relecture est
l'inverse.

*Et est-ce que ça empire ?* Le coût par recette et la durée moyenne portent
désormais leur écart avec la période précédente de même longueur. En dessous
d'une poignée de recettes de chaque côté, aucun écart n'est affiché : deux
contre trois n'est pas une tendance, et l'habiller en pourcentage serait du
bruit présenté comme une direction.

Les quatorze jours sont enfin quatorze jours. Les journées sans recette étaient
simplement absentes du graphique, ce qui collait deux dates éloignées l'une à
côté de l'autre et se lisait comme une activité continue qui n'a jamais eu
lieu. Elles sont là, à zéro, en gris.

Au passage : `_n()` n'existait pas dans le harnais de test, si bien qu'un écran
qui l'appelait passait au vert hors ligne et tombait en fatale sur un vrai
WordPress dès qu'il avait une ligne à compter.

## Version 0.7.0

**Des plafonds de dépense, et une file qu'on peut tenir.**

Trois craintes, trois plafonds. Celui du jour arrête un mauvais après-midi ;
celui des trente derniers jours arrête un mauvais mois que personne n'a vu
venir ; celui de la recette existait déjà. Ils comptent la dépense du site
entier, pas celle d'un rédacteur : quelqu'un qui n'en verrait que sa part ne
comprendrait jamais pourquoi son lot a été refusé. À zéro, aucun plafond.

Un lot est refusé avant de partir, contre son estimation, parce que refuser de
commencer ne coûte rien. Et une recette déjà en route est remise dans la file
avant sa vague suivante plutôt que marquée en échec : elle garde toutes les
étapes déjà faites et repart d'elle-même dès qu'il y a de la place. Cela amende
une décision du 20 septembre — « aucune étape jamais arrêtée pour le coût »
devient « aucun travail jamais perdu à cause du coût » : un lot bon marché au
départ ne dépasse plus le plafond simplement parce qu'il avait commencé.

La file, elle, se suspend. Rien de nouveau ne part ; ce qui est déjà commencé
garde tout et reprendra exactement là où il s'était arrêté. C'est le geste dont
on a besoin au moment précis où plusieurs recettes tournent, dépensent, et où
l'on ne sait pas encore pourquoi. Une recette peut aussi passer devant les
autres : une petite priorité sur la ligne, pas une seconde file, parce que deux
files finissent toujours par diverger.

Et quand la file n'avance plus, l'écran le dit — en nommant le cron, sauf quand
c'est un plafond, auquel cas il nomme le plafond.

## Version 0.6.0

**Installé sur un vrai WordPress, et six bugs que seul un vrai WordPress
pouvait montrer.**

`DELETE ... INNER JOIN ... LIMIT` ne fonctionne nulle part : MySQL interdit
LIMIT sur un DELETE multi-tables, SQLite n'a pas cette syntaxe du tout. La purge
des événements, livrée en 0.4.1, n'avait donc jamais supprimé la moindre ligne —
et la suite passait au vert parce qu'un faux `wpdb` accepte n'importe quelle
chaîne. Les lignes sont désormais choisies puis supprimées par leur identifiant.

`add_submenu_page( null, … )` met un avertissement de dépréciation en travers de
chaque écran concerné ; `remove_submenu_page` emporte le contrôle d'accès avec
lui et rend 403. Les écrans atteints depuis un ticket sont maintenant rattachés
à `options.php`, qui existe sans être un menu.

Le filtre `cron_schedules` traduisait son libellé pendant l'activation, avant
`init` : WordPress s'en plaignait à chaque première activation.

`AVG(passed / total)` est une division entière : le score de recherche
s'affichait à 0 % au lieu de 93 %. L'extracteur de chaînes ne connaissait pas
`_n()`, donc trois pluriels étaient restés en français dans l'interface
anglaise. Et le format monétaire était figé à la française : `0,36 $` en
anglais.

Enfin, un ticket affichait son état avant son nom : une ligne de grille placée
sans sa colonne laisse le placement automatique prendre la première.

**Politique de rétention.** Trois durées, parce que trois choses vieillissent
différemment : le déroulé (90 jours), les productions lourdes (365 jours, en
gardant verdict, revue et recette), les runs entiers (jamais, sauf demande
explicite, et jamais ceux qui ont produit un brouillon). Chacune est un filtre ;
à zéro rien n'est supprimé. L'écran Réglages dit ce qui est gardé et ce que cela
pèse.

## Version 0.5.4

**Les tests réels, et une documentation qui décrit le plugin qui existe.**

`GET /msrwa/v1/health` dit dans quel état est vraiment l'installation : version
et version de schéma, présence de chaque table et de chaque colonne ajoutée,
capacités réellement accordées à chaque rôle, cron armé, dossier de
téléversement accessible, tables dormantes de l'ancien plugin. Rien de sensible
— pas de clé, pas de contenu, aucune dépense. Elle existe parce que la couche de
tests réels pilote un site vivant par REST et n'a sinon aucun moyen de regarder
une table.

`tests/real/test-site.php` vérifie tout cela plus le refus de tout appel
anonyme. `tests/real/test-flow.php` fait un lot complet de bout en bout sur le
profil le moins cher : soumission, appariement, envoi, attente du cron,
brouillon créé, et trois vérifications qui ne peuvent se faire qu'en vrai — le
run n'a pas dépassé son plafond, il a coûté quelque chose, et l'estimation était
dans la bonne région. Il refuse de démarrer sans `MSRWA_TEST_BUDGET_USD` : il
dépense de l'argent réel.

`docs/ARCHITECTURE.md` décrivait l'ancien pipeline, supprimé depuis plusieurs
versions — pire qu'une absence de documentation. Réécrit : la forme d'un run, la
ligne autour du moteur, l'appariement comme étape du plugin, le modèle de
données et ce qui n'y est délibérément pas stocké deux fois, les droits, les
écrans, les routes, et dix invariants.

## Version 0.5.3

**Correction de la 0.5.2 : l'article relu reste stocké.** Ne pas dupliquer ce que
WordPress garde était juste pour l'essentiel — deux versions intermédiaires de
l'article que plus personne ne lit, la recette et le verdict qui vivent dans des
métadonnées que ce plugin a lui-même écrites, et les fichiers d'image en double
sur le disque. Soixante-dix kilobytes par run, sans rien perdre.

Mais pas pour l'article relu. `post_content`, c'est ce qu'un relecteur a modifié
depuis ; l'artefact, c'est ce que la machine a réellement produit. Les confondre
supprime la seule réponse possible à « est-ce le modèle qui a écrit cette
affirmation, ou quelqu'un l'a-t-il ajoutée ensuite ? » — et toute mesure de la
qualité du moteur faite sur un texte corrigé mesure les relecteurs. C'est aussi
ce qui fait qu'un run survit à la suppression de son brouillon.

Les deux sont donc lus côte à côte, et l'écran d'une recette le dit quand ils
divergent : « le brouillon a été modifié depuis sa génération, les contrôles
ci-dessous portent sur ce que la machine a écrit ».

**Un brouillon supprimé définitivement détache le run** au lieu de le laisser
pointer vers un article qui n'existe plus. Le run garde ce qu'il a produit.

## Version 0.5.2

**Ce que WordPress stocke n'est plus stocké une seconde fois.**

L'article finit dans `post_content`, la recette et le verdict dans les
métadonnées, les images dans la médiathèque. En garder une copie dans les tables
du plugin n'apportait rien et coûtait cher : l'article seul était conservé trois
fois — tel qu'écrit, tel que corrigé, tel que relu — soit environ 90 ko sur les
171 ko d'un run. Les deux images existaient aussi en double sur le disque, une
fois dans le dossier de travail et une fois dans la médiathèque.

Les artefacts restent tant que le run est en vol : le moteur les relit à chaque
tick de cron, et jusqu'à ce que le brouillon existe ils sont l'unique copie. Ils
partent au moment où WordPress les a — jamais avant, et seulement ceux-là. La
recherche, la revue, la vérification des faits et les prompts d'image n'ont pas
d'équivalent dans WordPress et sont conservés.

Ensuite, tout écran qui les lit va les chercher dans WordPress, de sorte que
rien ne change pour le lecteur — et la copie qu'il obtient est celle qu'un
relecteur a peut-être corrigée depuis, c'est-à-dire la bonne.

Un passage unique, borné et idempotent, libère au démarrage les doublons des
runs déjà terminés. Et un chemin enregistré qui sortirait du dossier de travail
du run n'est jamais supprimé, quoi qu'il prétende être.

## Version 0.5.1

**Export CSV, actions groupées, lots programmés.**

**L'export.** Trois formes, parce qu'on pose trois questions : une ligne par
recette pour « qu'a coûté le mois dernier », une par étape pour « où part
l'argent », une par appel pour « quel modèle est réellement facturé ». Diffusé
en flux et lu par pages de 500 : une année de runs n'a pas à tenir en mémoire
avant que quiconque puisse télécharger. Un coût inconnu y est vide et jamais
zéro — une colonne de zéros s'additionne en un total qui n'a jamais été payé —
et le fichier commence par une marque d'ordre des octets, sans quoi Excel rend
chaque accent illisible.

**Les actions groupées.** Arrêter, reprendre ou supprimer plusieurs recettes
d'un coup. Chacune est vérifiée séparément : une sélection qui contient une
recette qu'on n'a pas le droit de toucher en fait autant qu'elle peut et signale
le reste, plutôt que de tout refuser ou de tout faire. Une recette en cours
n'est jamais supprimée sous son propre worker, et la suppression appartient aux
administrateurs : elle détruit la trace de ce qui a été dépensé.

**Les lots programmés.** L'appariement se fait tout de suite — c'est ce qu'il
faut confirmer, et c'est la moitié bon marché. Ce qui attend, c'est l'envoi. Rien
n'est dépensé jusqu'à l'heure dite, et rien n'est envoyé depuis un navigateur :
le cron réclame le lot par un UPDATE conditionnel, donc deux passages simultanés
ne peuvent pas l'envoyer deux fois. Le cron passe désormais toutes les cinq
minutes, parce qu'un lot demandé à neuf heures qui part à dix a manqué son
heure.

## Version 0.5.0

**Le juge ne répond plus que de ce qu'on lui a montré** *(modification du moteur,
approuvée)*. Il réclamait les deux images par leur nom et faisait échouer
l'étape si l'une manquait — donc un run qui produisait délibérément une seule
image ne pouvait jamais être jugé, et se voyait reprocher l'absence de ce que
personne n'avait commandé.

Désormais l'étape joint les images qui existent, dit au juge lesquelles dans son
propre prompt, et ne lui demande de verdict que sur celles-là. Une image
présente mais illisible reste un échec : c'est un run cassé, pas un run plus
petit. Et une image qu'on lui a bien montrée et qu'il n'a pas jugée reste une
faute — le contrat n'est pas devenu une suggestion. Conséquence : le profil
« article et image à la une » a de nouveau un jugement final.

**Les estimations de prix, calculées et non devinées.** Elles viennent du
routage, des modèles, des plafonds de sortie et des tarifs que le moteur résout
réellement : changez une route sur l'écran Moteur et l'estimation suit. Le
composeur affiche deux nombres, parce que ce sont deux choses différentes : ce
que le lot coûtera probablement, et le plafond qu'il ne peut pas dépasser.

Deux bugs réels trouvés en la construisant. Les étapes d'image ne passent pas
par leur propre nom mais par `routing.image` : les chiffrer sur la route texte
donnait un collage à un vingt-cinquième de son prix. Et l'appariement lisait les
images via la route `research` au lieu de `vision` — même modèle aujourd'hui,
faux dès que l'une des deux change. Un test compare désormais l'estimation à
deux runs réellement facturés : 0,1119 $ estimé contre 0,1165 $ et 0,1128 $
mesurés.

**Les anciennes tables ne sont plus supprimées.** Une mise à jour qui détruit des
données est une mise à jour qu'on ne peut pas annuler. Elles restent en place,
inertes ; `MSRWA_DB::dormant()` dit lesquelles sont encore là, et leur
suppression appartient au propriétaire du site.

**`tools/promote.php`**, que les tests réclamaient depuis longtemps sans qu'il
existe : il recopie un gabarit de prompt dans les valeurs par défaut des
réglages, au lieu d'un copier-coller qui dérive.

## Version 0.4.2

**Tous les écrans sont refondus, et deux manques réels sont comblés.**

**Reprendre une recette arrêtée.** Il n'y avait aucun moyen de rattraper un run
échoué : il fallait tout relancer et tout repayer. Reprendre ne supprime que les
étapes qui ont erré — ce qui a réussi reste, artefacts compris. Un run mort au
collage redessine le collage et ne repaie ni la recherche, ni l'article, ni les
images qui étaient bonnes. C'est exactement pour cela que les étapes sont des
lignes et non un bloc.

**Le verdict là où le relecteur travaille.** Un rédacteur ouvre l'article, pas
les écrans du plugin. La boîte sur l'écran d'édition dit ce que la machine a
relevé, et dit surtout ce qu'elle n'est pas : l'avis du moteur sur sa propre
production, jamais une validation. Un avertissement sur un tableau de bord que
personne n'a ouvert n'a averti personne.

**Ce qui a été gardé de l'ancien écran d'exploitation.** Le rapport complet, avec
sa politique de sécurité de contenu et son confinement des images au dossier du
run — une image enregistrée ne doit jamais transformer un rapport en lecteur de
fichiers arbitraires. Le diagnostic local des routes aussi : quelle étape irait
où, avec ou sans clé, avec ou sans tarif connu, sans le moindre appel facturé.
Il vit désormais sur l'écran Moteur, où l'on se pose la question.

**La désinstallation.** Les tables, les options, le cron et les capacités
partent. Les brouillons produits et les images de la médiathèque restent :
supprimer une extension ne doit pas supprimer le travail d'un rédacteur.

**236 chaînes traduites** en anglais et en arabe, vérifiées par un test. Et un
test de rendu passe sur chaque écran, avec une base vide, pour les deux profils
de droits : un écran qui plante sur une installation neuve est la première chose
que verrait un nouvel arrivant.

## Version 0.4.1

**Une interface refondue, traduite, et une couche de données qui tient la
charge.**

**Le pass.** L'écran est construit autour de l'endroit qu'il remplace : le pass
d'une cuisine, où le travail arrive sur un rail, chaque ticket portant son état
sur sa tranche. On lit le rail avant de lire un ticket. L'ambre est la lampe
au-dessus du pass — structurelle et jamais sémantique, pour qu'on n'ait jamais à
se demander si elle veut dire « attention » ; les états gardent leurs propres
couleurs. Les chiffres sont en chasse fixe et en chiffres tabulaires, parce que
ces écrans sont des colonnes qu'on compare de haut en bas. Aucune police
distante : un plugin qui appelle Google à chaque page d'administration est un
problème de confidentialité sur le site de quelqu'un d'autre.

Tout est en propriétés logiques, donc l'interface arabe se retourne sans une
seule règle en plus, et chaque couleur est un jeton redéfini pour le thème
sombre de WordPress.

**Les écrans.** Le pass, un composeur numéroté — la séquence est réelle : rien
ne s'apparie avant que les photographies ne soient choisies, rien ne part avant
que l'appariement ne soit réglé —, une liste filtrable, une analyse, le moteur,
les réglages.

**Les droits, appliqués.** Un rédacteur voit son travail et pas celui des
autres ; une capacité `msrwa_view_all` héritée d'une version antérieure
n'élargit plus rien. Et l'argent regarde l'exploitant : un écran qui n'a pas le
droit d'afficher un montant ne le demande pas non plus à la base.

**Les données.** Les index suivent enfin les questions réellement posées
(`owner_created`, `owner_recent`). Les écritures d'une vague partent en une
requête par table au lieu d'une par ligne. Les fiches de contrôle comptent leurs
échecs à l'écriture, dans une colonne indexée, donc « quel contrôle échoue le
plus » lit des nombres au lieu d'analyser chaque bloc JSON jamais stocké. Et les
événements — la narration, qui grossit sans fin — sont purgés au-delà de
quatre-vingt-dix jours, pendant que les étapes, les appels et les productions
restent : ce sont eux les preuves.

**Trois langues, vérifiées.** Français, anglais, arabe. Un test refuse une
chaîne non traduite, un `.mo` plus vieux que son `.po`, et une traduction qui
perd un `%s` en route — gettext, lui, ne préviendrait personne.

## Version 0.4.0

**Les fondations de la montée en gamme : droits, profils de sortie, langues.**
Trois pièces, chacune décidée à un seul endroit, avant la refonte de l'interface
qui s'appuiera dessus.

**Les droits.** Trois capacités — `msrwa_create` pour soumettre et voir son
propre travail, `msrwa_view_all` pour voir celui de tout le monde,
`msrwa_manage` pour les réglages, le moteur, la suppression et le registre
complet. Chaque écran, chaque route et chaque requête interroge `MSRWA_Rights`
au lieu de tester une capacité sur place : c'est ainsi qu'une liste finit filtrée
sur un écran et pas sur le suivant. `scope_sql()` rend la clause de propriété
plutôt que de laisser l'appelant l'appliquer — une requête qui l'oublie n'a plus
de `WHERE` du tout et se voit.

**Les profils de sortie.** Article seul, article et image à la une, ou la chaîne
complète avec le collage. Tout passe par la couche de configuration appelante du
moteur : la liste des étapes à exécuter, et le retrait des dépendances devenues
sans objet. Rien dans `includes/engine/` ne sait que ces choix existent.

Le jugement final ne s'exécute que dans le profil complet, et ce n'est pas une
économie : le moteur lui réclame les deux images par leur nom, et confronter le
texte à une seule image — ou à aucune — n'a pas de sens. Les contrôles de texte,
eux, tournent dans les trois profils. Un test rejoue l'ordonnanceur du moteur sur
chaque profil et vérifie qu'aucun ne se bloque en attendant un artefact que
personne n'a demandé.

**Les langues.** Deux choses distinctes, et il ne faut pas les confondre : la
langue de l'interface, que WordPress connaît déjà, et la langue de l'article,
choisie par lot et transmise au moteur — un rédacteur francophone peut très bien
commander un article en arabe. Trois de chaque : français, anglais, arabe.

Il n'y a ni chaîne gettext sur cette machine ni étape de compilation dans ce
dépôt, donc `tools/i18n.php` fait les deux : il extrait les chaînes vers un
`.pot` et écrit les `.mo` au format GNU. Un test relit un catalogue octet par
octet plutôt que de faire confiance à l'écrivain — une table mal triée et
gettext ne trouve plus rien.

## Version 0.3.4

**Un test refuse désormais qu'un appel statique ne mène nulle part.** Il existe à
cause d'une panne réelle : la réécriture a supprimé la couche de stockage des
réglages et emporté `secret_for_save()` avec elle, pendant que `sanitize()`
continuait de l'appeler. PHP ne s'en aperçoit qu'au moment de l'appel — la suite
est restée verte et l'enregistrement d'une clé est mort sur un écran, pas ici.

`tests/test-resolves.php` parcourt le plugin et le moteur et vérifie que chaque
`self::` et chaque `MSRWA_X::` résout vers quelque chose qui existe. Un appel
protégé par `method_exists()` ou `class_exists()` a le droit d'être absent :
c'est à cela que sert la protection.

Il a trouvé treize autres appels du même genre, tous dans le code d'ancien
pipeline resté dans les classes que le moteur utilise : `MSRWA_Images::featured`,
`facebook`, `review`, `image_plan`, `MSRWA_Catalog::save_admin`, `sync` et leurs
voisines appelaient `MSRWA_OpenAI`, `MSRWA_Providers`, `MSRWA_Router`,
`MSRWA_Storage` et des méthodes de `MSRWA_DB` qui n'existent plus. Injoignables
aujourd'hui, mais fatales le jour où quelque chose les appelle. Supprimées : 237
lignes.

**`MSRWA_Cost` dit maintenant la vérité sur ce qu'il sait.** Il demandait à un
routeur quel modèle chaque étape utiliserait ; c'est le moteur qui choisit
désormais, et il ne le lui dit pas. L'estimateur chiffre donc les modèles qu'on
lui nomme, et une étape que personne n'a nommée est déclarée sans prix plutôt
que gratuite. Rien dans le plugin ni dans le moteur ne l'appelle : `load.php` du
moteur exige encore le fichier, sinon il serait supprimé.

## Version 0.3.3

Accès globaux réservés aux administrateurs. Auteurs/rédacteurs et éditeurs
voient uniquement leurs propres recettes et résultats, sans coûts ni détails
techniques. Aides statistiques repliables pour alléger l’interface.

Inspiration : [MS Cook Writer AI](https://github.com/sebmouad/MS-Cook-Writer-AI),
révision `2c00d91`, uniquement pour l’interface : cartes de synthèse dans les
statistiques. MS Recipes Writer conserve la rédaction multiple par lots,
l’appariement des photos par recette et le suivi individuel de chaque job.
Aucune modification du moteur, des prompts, des articles ou des images.
La rédaction multiple affiche un aperçu des titres saisis ; le suivi du lot
affiche des compteurs par état et une progression par recette actualisés
automatiquement, avec des libellés français et des badges lisibles.

## Version 0.3.2

Corrige l’erreur fatale `secret_for_save()` lors de l’enregistrement des clés.
Préserve les clés vides/masquées et les réglages non soumis. Le catalogue revient
aux valeurs par défaut lorsque l’ancienne table des modèles n’existe plus.
Test de régression utilisant la vraie classe de réglages et le chiffrement.

## Version 0.3.1

Interface harmonisée : navigation mobile en deux colonnes, cibles tactiles de
44 px, champs et tableaux adaptatifs, focus clavier visible, aperçus des photos,
indicateurs de défilement et avertissement pour les réglages non enregistrés.
Les messages de suivi sont accessibles aux lecteurs d’écran.

Navigation commune : Rédaction, Jobs (recherche, filtres et pagination),
Statistiques, Planificateur, Moteur et Réglages. Les listes de jobs sont limitées
au propriétaire pour les rédacteurs et accessibles globalement aux administrateurs.

Rapport de job utilisant le rendu complet du lab, intégré, ouvrable en pleine
page et téléchargeable avec images embarquées. Analytics par statut, étape,
fournisseur/modèle, tokens/cache, qualité, événements et livrables. Diagnostics
locaux des routes et configurations ; tests de syntaxe JSON avant sauvegarde.
Simulation des réglages non enregistrés : routes, tarifs connus, sources et
tailles des prompts, valeurs effectives. Aucun appel fournisseur ni sauvegarde.
Sauvegarde atomique en cas de JSON invalide. Surveillance cron toutes les cinq
minutes et réarmement des jobs en attente. Moteur inchangé.

Validation hors ligne uniquement : installation WordPress, rendu navigateur,
cron réel et validité distante des clés restent à vérifier sur un site de test.

## Version 0.3.0

**Le plugin est réécrit autour d'un seul rôle.** Le rédacteur fournit plusieurs
recettes et plusieurs photographies sans dire lesquelles vont ensemble. Le
plugin décrit chaque photographie, l'associe à sa recette, montre l'appariement
pour confirmation, construit un brief par recette et les envoie tous au moteur,
qui les traite ensemble. Quand un run se termine, son article, ses images et sa
fiche recette deviennent un brouillon WordPress.

Le moteur n'a pas changé. L'appariement est une étape du plugin : il passe par
la couche d'appel publique du moteur, avec son propre prompt, et rien dans
`includes/engine/` ne sait qu'il existe. Deux passes, parce qu'elles ne coûtent
pas la même chose : chaque photographie est décrite une fois, en parallèle —
c'est la moitié chère, facturée à l'image — puis un seul appel de texte lit les
descriptions en face des titres. Corriger une association ensuite ne coûte rien.

Dans le doute, le modèle n'associe pas : une photographie laissée de côté coûte
moins cher qu'une photographie attribuée au mauvais plat, qui illustrerait un
article entier. Et le rédacteur garde le dernier mot avant tout lancement.

**Ce qui a été supprimé.** L'ancien pipeline — `pipeline`, `queue`, `openai`,
`providers`, `publisher`, `images` de génération, `lists`, `stats`, `storage`,
`router`, `presentation` — faisait le travail que le moteur fait désormais
seul, en double et différemment. Environ 3 700 lignes, avec leurs tables :
`jobs`, `reservations`, `settings`, `settings_history`, `providers`, `models`,
`prompts`, `snapshots` et les tables `lab_*` sont supprimées une fois, sous leur
propre drapeau pour qu'une mise à jour ultérieure ne recommence pas.

Ce que le moteur utilise est resté intact : `json`, `recipe`, `quality`,
`prompt`, `images`, `catalog`, `cost`, et `MSRWA_Settings::defaults()`. Ce sont
ses dépendances, pas du code applicatif ; les supprimer aurait modifié le
moteur. Seul le stockage des réglages a changé de place — une option WordPress
au lieu de deux tables et d'un historique.

**Cinq tables** pour ce qui reste : `batches` (une soumission), `runs` (une
recette), puis `steps`, `calls`, `events` et `artifacts` qui reçoivent tout ce
que le moteur rapporte, en lignes plutôt qu'en un bloc JSON.

## Version 0.2.89

**Supprimer un run, et la documentation du laboratoire.** `MSRWA_Lab::delete()`
existait sans que rien ne puisse l'appeler ; il a maintenant sa route, son
bouton et sa confirmation — un run a coûté de l'argent réel et ne se régénère
pas gratuitement. Un run en cours doit être arrêté avant d'être supprimé.

`docs/ARCHITECTURE.md` décrit le laboratoire : le découpage en vagues qui
contourne le délai d'exécution PHP, les quatre tables, la reprise après un bail
expiré, et le chemin des clés. Deux invariants s'ajoutent : un coût inconnu
reste `NULL` et jamais zéro, et tout groupe de configuration du moteur doit
rester atteignable depuis l'écran Moteur — la suite le vérifie.

## Version 0.2.88

**Tout ce que le moteur rapporte est conservé, en lignes interrogeables.** Le
moteur ne rend pas seulement un résultat : un chiffre et une grille de contrôle
par étape, un compte de tokens et un prix par appel, un événement par chose
survenue, et les productions elles-mêmes. Quatre tables les reçoivent —
`lab_steps`, `lab_calls`, `lab_events`, `lab_artifacts` — au lieu d'un seul bloc
JSON. Un bloc répond « que s'est-il passé au run 12 » ; des lignes répondent
« combien coûte la revue sur tous les runs », qui est la question pour laquelle
le laboratoire existe.

Deux écrans les lisent. **Détail du run** montre chaque étape avec ses contrôles
non satisfaits, chaque appel avec son modèle, son point d'entrée, sa part de
cache et son prix, le verdict et ses remarques, les productions et leur taille,
puis le déroulé complet. **Mesures** agrège les runs terminés : où part l'argent
étape par étape, quel modèle est réellement facturé, quel contrôle nommé échoue
et à quelle fréquence, et combien de fois le juge approuve quoi que ce soit.

Un coût inconnu reste inconnu de bout en bout : la colonne est `NULL`, le total
compte les étapes non tarifées à part, et l'écran écrit « tarif inconnu » plutôt
qu'un zéro. Les tests le vérifient dans les deux sens.

La suite charge désormais la vraie classe base de données plutôt qu'un
stand-in : sa liste de tables et son filtrage des secrets sont ce sur quoi le
plugin repose réellement.

## Version 0.2.87

**Un écran « Moteur » : tout ce que le moteur utilise, modifiable.** Routage par
étape, plafonds de sortie, tentatives, budget, images, seuils d'un côté ;
fournisseurs, tarifs, niveaux, registre des étapes et gabarits de prompt de
l'autre. Chaque groupe s'affiche avec sa valeur effective et, dépliable à côté,
la valeur par défaut du moteur.

Ce qui est enregistré n'est que la différence. Un champ renvoyé tel quel n'est
pas stocké, donc le jour où le moteur change d'avis sur un plafond, le site
suit — sauf là où quelqu'un a explicitement écrit autre chose. L'écran montre en
bas la couche « appelant » réellement transmise au moteur.

Un test refuse désormais qu'un groupe de configuration du moteur n'ait aucun
champ pour l'atteindre : le jour où le moteur en gagne un, la suite le dit.

Les clés d'API ne s'éditent pas ici. Elles restent chiffrées dans la
configuration et rejoignent le moteur par l'autre chemin.

## Version 0.2.86

**Un sous-menu « Laboratoire » : lancer un run, le regarder, lire son
rapport.** On choisit un des sujets livrés avec le dépôt ou on tape un titre, on
fixe un plafond de dépense, on lance. Le cron prend le relais — l'onglet peut
être fermé, le run continue, et la page le retrouve exactement où il en est.

Le rapport est celui de la ligne de commande, rendu par le même code, ouvert
depuis la liste à n'importe quel moment : pendant un run il montre ce qui existe
déjà. La page prévient quand aucune clé n'est enregistrée et quand
`DISABLE_WP_CRON` est actif sans cron serveur en face, parce que ce sont les
deux façons dont un run reste immobile sans rien dire.

Réservé aux administrateurs : un run dépense de l'argent réel.

## Version 0.2.85

**Le laboratoire devient exécutable depuis WordPress, en tâche de fond.** Un run
dure environ quatre minutes et demie ; aucune requête PHP ne tient aussi
longtemps. Le travail est donc découpé là où le moteur le découpe déjà — à la
vague de dépendances : un tick de cron exécute une vague et consigne tout ce
qu'elle a produit, le tick suivant reprend de là. Une requête tuée coûte au pire
la vague en cours, jamais le run.

Trois pièces : le moteur accepte désormais qu'on lui remette directement une clé
d'API sous `settings.keys.<fournisseur>` — WordPress garde les siennes chiffrées
dans sa table et n'a pas d'environnement où les exporter, et `settings` est la
seule branche de configuration qui n'atteint jamais un enregistrement. Une table
`lab_runs` tient les runs à l'écart des jobs éditoriaux, parce qu'une
expérimentation n'a rien à faire dans les listes des rédacteurs. Et un worker
verrouillé, sur le modèle de la file existante, avance un run d'une vague par
tick, reprend un bail expiré et ne laisse jamais deux workers dépenser sur le
même run.

## Version 0.2.84

**Retrait du saut de relecture introduit en 0.2.83, qui ne se déclenchait
jamais.** L'idée tenait : la relecture est l'étape la plus chère du groupe de
vérification — 0,0104 $ et 35 s, environ 7 000 tokens de sortie — et elle
réécrivait l'article entier même quand la revue n'avait rien à redire.

Rejoué sur les cinq runs complets enregistrés, le saut ne se serait déclenché
aucune fois : la revue relève entre deux et cinq remarques à chaque article,
sans exception. Le garde était juste, ses tests aussi, mais c'était une porte
qui ne s'ouvre jamais. Le moteur ne garde pas de code qui ne fait rien.

L'économie annoncée n'existait pas. Ce qui reste de l'épisode est une mesure qui
vaut mieux que le code : la revue n'a jamais approuvé un article sans réserve,
et savoir si c'est un signal honnête ou un prompt qui doit toujours trouver
quelque chose se vérifie sans rien dépenser.

## Version 0.2.83

**Saut de relecture sur article propre** — ajouté puis retiré en 0.2.84, faute
de se déclencher une seule fois sur les runs mesurés.

## Version 0.2.82

**Les photographies citées sont lues ensemble, plus l'une après l'autre.** La
phase d'observation téléchargeait une image, la décrivait, puis passait à la
suivante : trois inconnus dont on attendait la latence bout à bout, 19,4
secondes sur un run mesuré. Les photographies n'ont aucun rapport entre elles,
et le moteur savait déjà lancer plusieurs appels de front — `http_many()`
existait pour les vagues et n'était pas utilisé ici.

Deux vagues concurrentes remplacent six appels en file. Mesuré sur les mêmes
références, avec le même instruction et les mêmes observations en sortie : 16,8
s en série contre 8,9 s ensemble, pour 0,0021 $ contre 0,0020 $. Les
vérifications restent inchangées et appliquées image par image — HTTPS, adresse
publique, corps borné, type réel — et un téléchargement refusé n'empêche plus
les autres.

## Version 0.2.81

**Le juge reçoit enfin les observations visuelles qu'on lui promettait.** Son
prompt annonçait « le dossier de recherche, avec les observations tirées de
photographies réelles de ce plat » — et `research_for_text()` retirait
précisément celles-là. Il décidait si une photographie ressemble à ce plat sans
avoir jamais vu à quoi ce plat ressemble, sinon par les quelques mots de couleur
et de texture glissés dans le brief visuel.

Il reçoit maintenant, explicitement : la **recette canonique de l'étape 2**,
qu'il prend comme référence, et les **observations visuelles de l'étape 1**,
lues dans les octets des vraies photographies.

**Le générateur et le juge reçoivent l'inverse l'un de l'autre, exprès.** Le
générateur dessine ce qu'on lui montre : lui montrer la garniture d'un autre
cuisinier, c'est la voir apparaître — filigrane, mains, poivrons, carottes,
quatre images perdues. Le juge, lui, compare : il doit la voir. Chaque
observation lui arrive donc avec son **rang** (ce plat, ou une variante, preuve
plus faible), sa **source**, et ce que la passe visuelle **n'a pas su
identifier**.

Et une règle qui compte autant que l'évidence elle-même : **une photographie
n'ajoute jamais une exigence.** Une assiette source servie avec du riz ne rend
pas le riz obligatoire. L'évidence sert à reconnaître le plat et à juger si la
cuisson est crédible, jamais à réclamer un élément que la recette canonique ne
contient pas.

Vérifié en réel sur le poulet yassa : image à la une `good`, collage `good`,
cohérence `good` — et **aucun constat ne réclame un élément vu sur une
photographie source**. Un contrôle hors ligne vérifie les deux sens : que le
juge reçoit l'inventaire du cadre, et que le générateur ne le reçoit toujours
pas.

## Version 0.2.80

**Le juge prend la recette canonique comme donnée** (décision du propriétaire,
2026-09-21). C'est la contradiction F14 de l'audit, tranchée.

L'étape recette reçoit l'ordre d'être cuisinable : « la complétude prime sur la
prudence », un ingrédient de base que la méthode exige manifestement — matière
grasse, sel, poivre, eau ou bouillon, le liquide d'une marinade — figure dans la
liste même si aucune source n'en donne la quantité, parce qu'une étape qui dit
de saisir ou d'assaisonner en a besoin. Le juge, lui, bloquait « un ingrédient
que nul fait de recherche n'appuie ». Un rédacteur obéissant était donc refusé
par un juge obéissant.

Désormais : **le silence de la recherche n'est pas un constat contre la
recette ; une contradiction l'est.** Restent bloquants — un ingrédient, une
étape, une température ou une durée que la recherche contredit directement ; un
ingrédient que la recherche tient pour essentiel et que la recette omet ; et une
affirmation de l'**article** qui ne se trouve ni dans la recette ni dans la
recherche.

Vérifié en réel sur le poulet yassa, dont la recette canonique porte six
ingrédients qu'aucune source ne chiffre :

| | avant | après |
|---|---|---|
| huile, sel, poivre, eau, bouillon non sourcés | bloquants | **aucun constat** |
| autorité du juge | la recherche | **la recette canonique** |
| image à la une | — | `good` |

Les refus restants sont justes : l'article ajoutait du persil absent de la
recette, et un panneau du collage montrait les hauts de cuisse crus déjà dans le
liquide. Le juge cite maintenant la recette canonique comme référence, et non le
silence des sources.

## Version 0.2.79

Premier lot de corrections issues de l'audit externe du 2026-09-21. Chaque
constat a été **reproduit** avant d'être corrigé ; le script de régression que
l'audit fournissait sans l'avoir exécuté échouait bien sur ses quatre
assertions, et il entre dans la suite.

**F01 — la porte d'acceptation (P0).** `decide()` vérifiait trois des contrats
qu'il venait de noter et lisait `approved` avec `!empty()`. La chaîne `"false"`
est vraie en PHP : elle approuvait. Et le contrôle « une approbation et un
constat bloquant ne tiennent pas ensemble » était calculé puis ignoré. La
décision est désormais dans `MSRWA_Engine_Score::accepts()`, testable seule :
booléen `true` strict, zéro constat bloquant, et tous les contrats structurels —
dont le réalisme de chaque image et la validité des cibles. Vérifié sur les neuf
verdicts réels du dépôt : **aucune décision ne change**.

**F03 — les redessins comptés deux fois.** Une image régénérée était
enregistrée comme étape *et* rajoutée au coût de l'approbation. Prouvé sur un
passage réel : $0,0281 comptés deux fois, **22 % de surcoût affiché**. Le
passage complet de la 0.2.78 a réellement coûté **$0,1437**, pas $0,1747.

**F05 — un tarif inconnu devenait zéro.** `(float) null` vaut `0.0` : un
passage sur un modèle sans tarif publié s'affichait gratuit et franchissait le
budget. Le coût inconnu reste inconnu, `totals()` compte les étapes non
tarifées, et le budget s'arrête plutôt que de valider ce qu'il ne peut pas
vérifier.

**F11 — des identifiants pouvaient entrer dans les rapports.** Un appelant
pointant vers sa propre passerelle peut écrire un jeton dans un en-tête ou dans
l'URL. Les en-têtes sensibles, les `user:pass@` et les paramètres de clé sont
masqués avant toute sérialisation et sur chaque événement d'appel.

**F12 — les passages sauvegardés n'étaient pas déballés.** `--canonical=<run>.json`
transmettait l'enveloppe entière — `ok`, `steps`, `totals`, `events` — à la
place de la recette. Le chargeur extrait l'artefact nommé et refuse un passage
qui ne le contient pas.

**F35 — le rapport rendait du HTML de modèle tel quel.** L'article était
concaténé dans la page. Il est reconstruit depuis une liste blanche : les
balises dont une recette a besoin, aucun attribut sauf un `href` de schéma sûr.
Vérifié : `<script>`, `<iframe>`, `onclick`, `onerror`, `javascript:` disparaissent ;
titres, gras, listes, tableaux, liens et le marqueur de page survivent.

Reste ouvert et demandant un arbitrage : le siège de test du transport, F02,
F04, F06, F07 (admission stricte), et les deux contradictions de prompts
F14/F15.

## Version 0.2.78

**Les étapes indépendantes partent ensemble.** Le moteur savait déjà lesquelles
ne s'attendent pas — c'est ce que déclare `needs` — mais il les appelait l'une
après l'autre. Ce qu'il y gagnait n'était que de l'attente.

| Vague | avant | après |
|---|---:|---:|
| article + image à la une + collage | 137,9 s | **43,4 s** |
| revue + fact-check | 65,0 s | **21,8 s** |
| **passage complet** | **436 s** | **290 s** (−33 %) |

Seule la première tentative est partagée. Une étape qu'il faut redemander l'est
seule : à ce moment-là elle ne fait plus la même chose que les autres.

Le transport se sépare en deux : `plan_text()`, `plan_image()`, `plan_judge()`
préparent un appel, `read()` en lit la réponse. Un échec dans un lot reste un
échec de cet appel-là et ne dérange pas les autres.

**Le cache de prompts : mesuré, et inexploitable ici.** Un appel identique est
mis en cache à 100 %. Le *même préfixe de 6 700 jetons avec une fin différente*
ne l'est pas du tout — 0 %. Sur ce point d'accès le cache porte sur l'entrée
entière, pas sur son préfixe : réorganiser le pipeline pour partager un préfixe
n'aurait rien rapporté. `store: false` n'y est pour rien, contrairement à
l'hypothèse de départ.

**Une réponse qui n'analyse pas est conservée.** C'était le seul échec qu'un
passage ne savait pas expliquer après coup : le barème disait « non analysable »
et le texte était jeté. Le moteur en garde 2 000 caractères et dit comment il
commence.

Cela s'est payé immédiatement : la revue s'arrêtait pile sur son plafond de
3 000 jetons, coupée au milieu de son JSON, facturée en entier, notée 0/3.
**Sixième plafond de cette liste trouvé de cette façon** — porté à 6 000.

**Et une régression trouvée par un nouveau contrôle.** En déplaçant un bloc,
`MSRWA_Engine::observe()` a disparu avec lui. La suite hors ligne est restée
verte ; la panne est sortie en erreur fatale au milieu d'un passage réel, après
que l'appel a été payé. Un contrôle lit désormais le source du moteur et échoue
si une méthode appelée n'existe pas.

Passage complet vérifié : **approuvé**, les quatre verdicts `good`, aucun
constat, 290 s, $0,1747.

## Version 0.2.77

**Les images refusaient toujours pour la même raison, et ce n'était pas le
prompt.** Chaque défaut mesuré venait de la même source : la passe visuelle
inventoriait ce qu'il y avait *dans la photographie* au lieu de décrire *le
plat*, et ces phrases voyageaient jusqu'au prompt image comme des faits sur le
plat. Le modèle les dessinait.

| Ce qui a été dessiné | D'où cela venait |
|---|---|
| « La Cuisine de Biscottine » et une bordure jaune | le filigrane et le cadre d'une photo source |
| le plat tenu à deux mains | la mise en scène de la photo source |
| des poivrons, une tranche de citron | « lanières rouges et vertes », « un demi-fruit jaune » |
| des carottes | « morceaux orange visibles » |

Cinq défauts, cinq refus, **un seul canal** : `observable_details` et
`composition`. Ce sont des inventaires du cadre. `colours` et `textures`
décrivent la nourriture. Le prompt image ne reçoit plus que ces deux-là.

Trois corrections, à trois niveaux :

1. **À la source.** La passe visuelle décrit le plat et rien d'autre : ni
   accompagnement, ni garniture, ni seconde assiette, ni couverts, ni mains, ni
   personne, ni vêtement, ni fond — et jamais le texte, le filigrane, le logo ou
   la bordure posés sur l'image. Ce qu'elle ne peut pas identifier part dans
   `uncertainties`, au lieu d'être décrit par sa forme et sa couleur.
2. **En défense.** Un filtre phrase par phrase retire ce qui parle de la
   photographie plutôt que du plat. Une mauvaise proposition ne coûte plus toute
   l'observation.
3. **En dernier recours.** Six règles courtes closent chaque prompt image — là
   où un modèle pèse le plus — et chacune est une règle déjà énoncée plus haut
   qu'une génération réelle a quand même enfreinte.

**Mesuré, même recette, même dossier de recherche** (le poulet yassa, refusé
trois fois de suite sur ses images) :

| | avant | après |
|---|---|---|
| image à la une | `bad` | **`good`** |
| collage | `bad` | `reservations` |
| cohérence | `bad` | **`good`** |
| constats bloquants | 3 | **0** |

**Et le prompt maigrit de 31 %** — 34 104 → 23 678 caractères pour la paire.
Un appel image est facturé surtout sur ce qu'on lui envoie : l'entrée
représentait 51 % du coût de l'image à la une et 69 % de celui du collage. La
paire passe de $0,0598 à $0,0535. Le vrai gain reste le cycle de refus évité :
$0,0615 et 75 secondes.

Le dossier brut ne suit plus le brief visuel qui le distillait déjà : c'était
38 % du prompt de l'image à la une, en doublons — et cela doublait aussi le
poids des mauvaises phrases.

## Version 0.2.76

**Rien n'est figé dans le moteur.** Points d'accès des fournisseurs, tarifs des
modèles, paliers, registre des étapes, prompts, chaque seuil de notation et
chaque plafond : tout est une clé de configuration, et l'appelant peut remplacer
n'importe laquelle sans toucher à celles d'à côté.

| Clé | Ce qu'elle décide |
|---|---|
| `providers` | points d'accès, en-têtes, variables de clé, outil de recherche web |
| `models` | tarif par million de jetons, `[entrée, sortie]` |
| `tiers` | ce que `fournisseur:low\|medium\|high` désigne |
| `steps` | libellé, poste de budget, dépendances, gabarit de prompt |
| `prompts` | le texte du prompt, à la place du gabarit livré |
| `thresholds` | chaque nombre auquel un contrôle se compare |
| `limits` | budget, délai HTTP, taille d'image, photographies analysées |

La surcharge est chirurgicale : changer le point d'accès d'un fournisseur
conserve ses en-têtes, recharger une étape sur un autre poste conserve ses
dépendances, déplacer un seuil laisse les autres à leur valeur mesurée. Un
appelant peut aussi **ajouter** une étape que le moteur n'a jamais eue ; elle
prend part à l'ordonnancement par vagues comme les autres.

`MSRWA_Engine_Rates` disparaît : les tarifs et les paliers sont dans la
configuration, donc le laboratoire et le plugin ne peuvent plus diverger.
`tests/test-engine-configurable.php` lit le code du moteur et échoue si une URL
ou un nom de variable de clé y réapparaît.

**Le moteur rend compte de ce qu'il fait des données, pas seulement de ce que
cela coûte.** Trois nouveaux types d'événement :

- `config` — quelles clés l'appelant a surchargées, et la provenance complète :
  `engine`, `caller` ou `run`, pour chacune.
- `input` — d'où vient le prompt, sa taille, la taille de l'entrée, le plafond de
  sortie, quels artefacts étaient attachés, si la recherche web était active.
- `call` — quel point d'accès a répondu, sur quel modèle et quel palier, les
  jetons dans chaque sens, **combien le fournisseur a servis depuis son cache**,
  le prix, le statut d'arrêt.

Chaque étape enregistre aussi le prompt exécuté et d'où il venait.

**Le rapport montre tout cela** : la recette canonique se lit enfin *comme une
recette* — c'est la référence à laquelle tout se mesure, elle méritait mieux
qu'un vidage clé/valeur — plus une ligne par appel fournisseur, ce que chaque
étape a reçu, et la configuration effective avec la couche qui a décidé chaque
clé.

**Un bug trouvé par ce nouveau relevé.** Les noms de modèles contiennent des
points, et la lecture par chemin pointé découpait dessus :
`models.openai.gpt-5.6-luna` ne résolvait rien, et un passage complet se
rapportait comme gratuit. Sans la ligne « tarif inconnu » par appel, un total à
$0,00 serait passé pour une bonne nouvelle. Corrigé, et un contrôle vérifie
désormais que **chaque modèle livré et chaque palier se tarifient**.

## Version 0.2.75

**« Relancer jusqu'à approbation » est mesuré, pas supposé.** Sur les mêmes
artefacts du poulet yassa : premier verdict *refusé* — des poivrons et une
tranche de citron sur la photographie principale, absents de la recette ; trois
feuilles de laurier là où elle en prévoit une. Les deux images sont redessinées
avec ces constats portés dans le prompt comme corrections, et le second verdict
**approuve**, ne laissant que trois constats mineurs sur la présentation, à
l'appréciation de l'éditeur.

$0,1414 et 78 s pour la boucle, contre $0,0075 pour un verdict qui approuve du
premier coup. Ce qui compte : les ingrédients inventés ne sont pas revenus. Une
correction n'est pas un second coup de dé.

Les deux régénérations sont enregistrées comme leurs propres étapes, chacune
avec son coût dans son propre poste de budget, et chaque image conserve les
corrections dont elle est née.

Mesures reportées dans [`.claude/docs/LAB-RESULTS.md`](.claude/docs/LAB-RESULTS.md).

## Version 0.2.74

**Le juge ne recevait pas l'article.** `MSRWA_Engine_Input::build()` n'avait
aucune branche pour l'approbation finale : elle retournait le prompt seul. Le
juge voyait les deux images et rien d'autre — ni article, ni recette, ni
recherche — et refusait en le disant : « L'article complet n'a pas été fourni ».

Un passage complet sur le poulet yassa l'a montré : l'approbation a refusé trois
fois de suite, au tarif plein, sans qu'aucun contrôle hors ligne ne puisse le
voir. Corrigé et vérifié sur les mêmes artefacts : **10/10**, article jugé
*conforme*, et le refus porte désormais sur ce qu'il doit — des poivrons et une
tranche de citron absents de la recette, trois feuilles de laurier là où elle en
prévoit une.

**Un refus que le moteur ne peut pas corriger n'est plus réessayé.** Quand le
verdict est sain, refusé, et qu'aucune image n'est mise en cause, reposer la
même question au même juge sur les mêmes artefacts est un second coup de dé.
C'est une décision, pas un essai raté : elle part à l'éditeur. Cela coûtait deux
appels par passage.

Un contrôle hors ligne vérifie maintenant que chaque étape reçoit ce à quoi elle
est mesurée — recette, recherche, article — et il échoue bien si on retire la
branche.

### Premier passage complet du nouveau moteur

Poulet yassa, dix étapes, bout en bout :

| | |
|---|---|
| temps | 346,7 s |
| coût | $0,1274 |
| jetons | 120 217 entrée · 32 028 sortie |
| texte / image à la une / collage / recherche | $0,0370 · $0,0278 · $0,0350 · $0,0277 |

Recherche 14/14 avec deux photographies réelles lues dans leurs octets, recette
4/4, article 10/10, revue 3/3, fact-check 5/5, corrections 2/2, langue 6/6.

## Version 0.2.73

**Huit outils deviennent un.** `tools/lab.php` est la seule commande ; le reste
est le moteur.

| Avant | Maintenant |
|---|---|
| `prompt-lab.php` | `lab.php step <étape>` |
| `image-lab.php` | `lab.php step featured_image\|facebook_image` |
| `approval-lab.php` | `lab.php step final_approval` |
| `judge-stability.php` | `lab.php judge --draws=5` |
| `build-standalone-report.php` | `lab.php report` |
| `promote-prompts.php` | `lab.php prompts` |
| `prune-runs.php` | `lab.php prune` |
| `format-cost.php` | `tools/experiments/` — sa question est tranchée |
| — | `lab.php run` : la recette entière, en un appel |

**Le rapport HTML lit une exécution, plus treize fichiers.** Il fallait nommer
treize chemins sur la ligne de commande, ce qui permettait d'assembler un
rapport à partir de passages n'ayant jamais appartenu à la même recette. Il part
désormais d'un `MSRWA_Result` et de rien d'autre.

Ce qu'il montre en plus : le coût par poste de budget, le nombre d'essais par
étape, chaque vérification avec ce qu'elle a mesuré, les corrections réellement
appliquées au texte, et le déroulé de l'exécution — vagues, reprises,
avertissements, échecs. La décision d'approbation passe en bandeau au-dessus des
quatre verdicts : elle est leur somme, pas leur égale.

**Deux trous du moteur, trouvés en retirant les outils.** L'analyse des
photographies citées et celle des images fournies par l'éditeur ne vivaient que
dans `prompt-lab.php`. Une exécution déclenchée par le plugin aurait rendu une
recherche sans aucune observation visuelle — et tous les prompts suivants
s'appuient dessus. Les deux passes sont dans le moteur, facturées à l'étape de
recherche, à laquelle elles appartiennent.

`tools/lib/` passe de 157 à 133 lignes et ne fait plus qu'une chose : lire une
fiche d'essai ou un passage sauvegardé sur le disque. Le registre d'étapes que
le laboratoire tenait en double a disparu ; il n'y en a qu'un, celui du moteur.

## Version 0.2.72

**La boucle de correction se referme.** La revue et le fact-check produisaient
des constats depuis des semaines et rien ne les appliquait — en réalité je les
appliquais à la main, dans le générateur de rapport, codés en dur par recette.

Le fact-check cite déjà mot pour mot la phrase qu'il conteste et fournit celle
qui la remplace ; son propre barème vérifie que la citation est bien dans
l'article. L'appliquer est donc une substitution, pas un jugement : aucun modèle
ne tourne, cela ne coûte rien, et rien ne peut être inventé.

La nouvelle étape `corrections` enregistre les deux moitiés : ce qu'elle a
appliqué, et ce qu'elle n'a pas su localiser dans le HTML — le fact-check répond
en phrases simples alors que l'article est du HTML, donc une phrase coupée par
une balise est un cas réel. Ce reste part à l'éditeur au lieu de disparaître.

Un constat de revue ne porte pas de citation : c'est un avis sur une section.
Aucun n'est appliqué ici ; ils accompagnent le reste jusqu'à l'éditeur.

La correction de langue tourne désormais sur le texte déjà corrigé sur le fond,
pour la même raison que l'approbation était passée derrière la relecture :
corriger la langue d'un paragraphe sur le point d'être remplacé est du travail
perdu.

| Vague | Étapes simultanées |
|---|---|
| 1 | recherche |
| 2 | recette canonique |
| 3 | **article, image à la une, collage** |
| 4 | **revue, fact-check** |
| 5 | corrections factuelles (aucun modèle) |
| 6 | correction de langue |
| 7 | approbation finale |

## Version 0.2.71

Le moteur a une porte d'entrée : `MSRWA_Engine::run()`.

Un appel, un brief, un `MSRWA_Result` : les artefacts produits, le détail par
étape, les totaux dans les quatre postes de budget, les erreurs et les
événements tels qu'ils se sont produits. Rien ne lève d'exception et rien ne
sort du processus — un plugin ne peut se le permettre en pleine requête — donc
un échec est une valeur, enregistrée à côté de ce qui a réussi.

- Les étapes tournent par vagues de dépendances : l'article s'écrit pendant que
  les deux images se dessinent, puis les trois relectures tournent ensemble.
- Trois chemins d'appel selon ce que l'étape demande au modèle : texte, génération
  d'image, et le juge qui lit les octets des deux images à la fois.
- « Relancer jusqu'à approbation » converge : un refus régénère **les images que
  le juge a bloquées**, avec ses constats comme corrections, avant de redemander
  un verdict — au lieu de relancer le même dé.
- Le budget arrête le passage avant l'étape qui le dépasserait.
- Le moteur ne lit plus rien sur le disque : les artefacts arrivent en mémoire.
  Charger un passage sauvegardé est le travail du laboratoire, pas du moteur.
- La configuration parle **le vocabulaire du moteur** : c'est l'appelant qui
  traduit le sien, jamais l'inverse. Le moteur est la pièce réutilisée ; il ne
  peut pas porter une table de correspondance par appelant.

## Version 0.2.70

Deuxième étape du déplacement : **tout le comportement est dans le moteur**.

| Classe | Rôle | Lignes |
|---|---|---:|
| `MSRWA_Engine_Config` | valeurs par défaut, réglages du plugin, demandes d'un passage | 195 |
| `MSRWA_Engine_Steps` | ce qu'exige chaque étape, donc l'ordre et le parallélisme | 98 |
| `MSRWA_Engine_Input` | ce qu'une étape reçoit avant de tourner | 420 |
| `MSRWA_Engine_Score` | ce qui compte comme réussi, vérifié sur les données rendues | 278 |
| `MSRWA_Engine_Call` | tous les appels fournisseurs, normalisés | 277 |
| `MSRWA_Engine_Rates` | tarifs publiés et paliers | 72 |
| `MSRWA_Result` | l'enveloppe rendue, et le rapport d'avancement | 107 |

`tools/lib/steps.php` passe de **762 à 157 lignes** : il ne reste que ce qui est
propre au laboratoire — amorçage des doubles de test, lecture des réglages
livrés, registre pour la ligne de commande — et des adaptateurs vers le moteur.

**Les prompts déménagent dans `includes/engine/prompts/`.** Ce sont les données
du moteur : il les exécute, il les porte. Les fiches d'essai restent côté
laboratoire, qui indique sa racine au moteur.

**La configuration se résout en trois couches**, chacune écrasant la
précédente : les valeurs par défaut du moteur, les réglages du plugin traduits
dans le vocabulaire du moteur, puis ce que demande ce passage précis. Le
résultat effectif est consigné sur l'exécution — clés d'API exclues — pour qu'un
rapport puisse répondre des mois plus tard à « avec quoi cela a-t-il tourné ».

Une valeur écrite à la main est ramenée dans ce que les fournisseurs acceptent
réellement, et une clé inconnue est ignorée plutôt que refusée : un plugin plus
ancien et un moteur plus récent continuent de fonctionner ensemble.

## Version 0.2.69

**Le laboratoire devient le moteur du plugin** — première étape. Le code
d'orchestration quitte `tools/` pour `includes/engine/`, chargé par le plugin
comme par le laboratoire. C'est le même code des deux côtés, par construction :
un prompt prouvé au banc d'essai ne peut plus diverger de celui qui tourne en
production.

Le moteur ne touche aucune fonction WordPress. Le plugin lui passe ses réglages
et récupère un résultat.

- **`MSRWA_Result`** — l'enveloppe que rend chaque exécution : `ok`, artefacts,
  étapes, totaux, erreurs, événements. Elle sert de rapport d'avancement pendant
  l'exécution *et* de trace après, si bien que l'écran d'administration et le
  rapport HTML lisent la même chose. Un observateur reçoit chaque événement au
  moment où il se produit, pour une barre de progression réelle.
- **Une panne est une valeur, jamais une mort.** Clé absente, modèle inconnu,
  fournisseur en erreur : tout revient en donnée. Le laboratoire pouvait se
  permettre `exit(2)` ; un plugin ne le peut pas. Les neuf points de sortie
  brutale ont disparu.
- **`MSRWA_Engine_Steps`** décrit ce que chaque étape exige avant de tourner. Le
  graphe qui en découle montre où se gagne le temps :

| Vague | Étapes simultanées |
|---|---|
| 1 | recherche |
| 2 | recette canonique |
| 3 | **article, image à la une, collage** |
| 4 | **revue, fact-check, correction** |
| 5 | approbation finale |

  Les images ne dépendent que de la recette et de la recherche, pas de
  l'article : elles peuvent tourner pendant sa rédaction. Chemin critique estimé
  à ~186 s contre ~250 s en série.

- **Correction éditoriale trouvée en dessinant le graphe** : l'approbation
  dépend désormais de la correction, pas de l'article brut. On jugeait le texte
  avant sa relecture, donc pas celui que le lecteur reçoit.

`tools/lib/` ne contient plus que des adaptateurs vers le moteur, le temps de
migrer les appelants ; ils disparaîtront à l'étape suivante.

## Version 0.2.68

**`recipe_outline` ne laisse plus de trou.** Le contrat exigeait sept valeurs
mais tolérait qu'elles disparaissent : sur le poulet yassa, la recherche n'en
rendait que deux sur quatre. Les clés sont désormais **toujours présentes**, le
modèle doit chercher chaque figure avant d'y renoncer — une page de recette
donne presque toujours un nombre de parts et un temps de cuisson, et le total
est la somme des deux quand les deux existent — et `null` ne s'écrit que pour une
valeur réellement cherchée qu'aucune source ne donne.

Vérifié sur les deux plats : poulet yassa **14/14**, `cook_minutes` à `null`
honnêtement plutôt qu'omis ; souris d'agneau **14/14**, les huit clés présentes
et les quatre figures trouvées.

**L'approche fusionnée est retirée.** Le gabarit, l'étape de laboratoire, son
score et ses tirages quittent le dépôt. La mesure qui l'a écartée reste dans
l'historique des versions et dans `docs/BUILD-CHECKLIST.md` (A32) : 1,3 %
d'économie pour 13 secondes de plus, et un échec de validation cinq fois plus
cher à rejouer.

## Version 0.2.67

Le gabarit fusionné rejeté quitte `tools/prompts/` pour `tools/experiments/`.
Il restait dans le jeu actif et faisait échouer deux contrôles qui comptent les
prompts livrés — et j'avais poussé la 0.2.66 sans les relancer, ce qui est
exactement la règle que ce dépôt s'impose.

`tools/experiments/README.md` conserve la mesure, la raison du rejet et la
commande pour la rejouer. Le laboratoire sait toujours exécuter l'expérience :
une réponse mesurée doit survivre à la question.

## Version 0.2.66

**Fusionner recherche et recette canonique : mesuré, puis écarté.**

La question était légitime — la recherche rend déjà les ingrédients avec leurs
quantités et les étapes dans l'ordre, soit presque la recette. Le gabarit fusionné
`research_recipe.tpl.txt` fait les deux travaux en un appel et il **fonctionne** :
17/17 sur le poulet yassa, recette valide, 10 ingrédients, 12 étapes.

Il ne rapporte simplement rien. Sur le même plat :

| | Temps | Coût | Score |
|---|---:|---:|---|
| Recherche + recette, séparées | 49,5 s + 21,8 s = **71,3 s** | 0,0183 $ + 0,0047 $ = **0,0230 $** | 13/14 puis 4/4 |
| Fusionnées | **84,5 s** | **0,0227 $** | 17/17 |

Soit 1,3 % d'économie — dans le bruit — pour **13 secondes de plus**. La sortie
d'un seul appel se génère séquentiellement : 8 635 jetons d'affilée coûtent plus
de temps que 6 943 puis 3 162 dans deux appels.

Trois raisons de garder la séparation, au-delà des chiffres :

1. **Le coût d'un échec.** Une recette rejetée par le validateur se rejoue
   aujourd'hui pour 0,0047 $. Fusionnée, il faudrait relancer l'appel avec
   recherche web : 0,0227 $, soit **cinq fois plus**.
2. **Deux métiers différents.** La recherche consigne ce que disent les sources,
   désaccords compris ; la recette tranche. Un seul appel doit faire les deux, et
   le risque est qu'il tranche en silence au lieu de consigner le désaccord.
3. **Le cache ne joue pas en sa faveur.** Les reprises d'un appel identique sont
   mises en cache à 99,96 % ; plus l'appel rejoué est gros, plus on paie la part
   non mise en cache.

Le gabarit reste dans le dépôt, comme `article_full` avant lui : une expérience
mesurée et rejetée vaut mieux qu'une question rouverte tous les trois mois.

Trouvé au passage : la recherche seule n'a donné que 2 des 4 figures de
`recipe_outline` sur ce plat, là où la version fusionnée en donne 4 — le contrat
d'`outline` mérite d'être resserré.

## Version 0.2.65

**La recherche devient le socle réel du pipeline.** Elle ne rend plus des faits
épars mais la recette telle que les sources la décrivent, sourcée ligne à ligne :

- `recipe_outline` — portions, temps de préparation, de cuisson, total, catégorie,
  cuisine, difficulté ;
- `ingredients` — nom, quantité, unité, **rôle dans le plat**, caractère
  **essentiel**, et sa source ;
- `preparation` — étapes numérotées dans l'ordre réellement exécuté, chacune avec
  son **repère visuel de fin** et, quand la source les donne, minutes et
  température ;
- `substitutions`, `accompaniments`, `common_failures`, `storage` — uniquement ce
  qu'une source atteste.

**Les images sont désormais hiérarchisées.** Rang 1 : de vraies photographies de
*ce* plat, trois sur trois domaines différents. Rang 2 : jusqu'à deux
photographies d'une variante proche ou du composant caractéristique, réservées à
la direction visuelle quand le rang 1 est mince. Chaque image porte son rang, si
bien qu'une étape ultérieure sait le poids à lui accorder.

Mesuré sur la souris d'agneau : **14/14**, 10 ingrédients tous sourcés, 10 étapes
portant toutes leur repère, 3 références rang 1. `research_max_output_tokens`
passe de 7 000 à 12 000 — le paquet plus riche dépassait l'ancien plafond.

### Format d'échange et cache : mesurés, pas supposés

`tools/format-cost.php` compare plusieurs rendus du même paquet et lit les jetons
*facturés par le fournisseur*, pas une estimation.

| Format | Caractères | Jetons | Coût |
|---|---:|---:|---:|
| enregistrements « pipe » | 9 406 | **2 461** | 0,00053 $ |
| JSON compact (actuel) | 9 649 | 2 500 | 0,00054 $ |
| lignes indentées | 9 781 | 2 609 | 0,00056 $ |
| JSON indenté | 11 448 | 2 871 | 0,00062 $ |

**Changer de format ne vaut pas la peine : 1,6 %.** Le gain réel avait déjà été
pris en abandonnant le JSON indenté (−13 %). Le tokeniseur digère très bien la
ponctuation JSON, et nous perdrions un format que tout le monde sait lire.

**Le cache, lui, est massif — mais seulement sur les appels identiques.** Un même
prompt rejoué : **6 825 jetons sur 6 828 mis en cache**, soit 99,96 %. Deux
recettes différentes partageant 1 622 jetons de gabarit : **0**. Conséquence
directe et utile : les reprises — la relance d'un jugement mal formé, la
régénération après refus — ne coûtent presque rien en entrée. La stratégie
« réessayer jusqu'à approbation » que vous avez choisie est donc moins chère que
le prix affiché par appel ne le laisse croire.

## Version 0.2.64

Le juge a été mesuré au lieu d'être supposé : mêmes artefacts, même prompt,
plusieurs passages par fournisseur (`tools/judge-stability.php`).

| Fournisseur | Verdicts exploitables | Accord entre eux | Coût / appel |
|---|---:|---:|---:|
| `gpt-5.6-luna` | 4/5 | 4/4 | **0,0061 $** |
| `claude-sonnet-5` | 3/3 | 3/3 | 0,153 $ |
| `gemini-3.5-flash` | 3/5 | 2/3 | 0,017 $ |

**Deux faux positifs systématiques trouvés et corrigés.** Sur des artefacts
identiques, le juge n'approuvait que 2 fois sur 5. Les refus portaient sur des
brins verts « ressemblant à du persil » — alors que le thym, le romarin et le
laurier figurent dans cette recette — et sur **un verre de vin posé à côté de
l'assiette**, lu comme un ingrédient inventé. Le contrôle ne juge désormais que
ce qui est *dans* le plat : une boisson, une bouteille, un bol à côté, des
couverts ne sont jamais un ingrédient ; et avant de déclarer un ajout, le juge
doit chercher dans la liste ce que l'élément pourrait être, les herbes hachées
étant indiscernables à cette résolution. Résultat immédiat : de 2/5 à **4/4**
d'accord.

**`approval_max_output_tokens` passe de 6 000 à 14 000** — le cinquième plafond
trop bas de ce projet. Claude rédige des verdicts nettement plus longs que
OpenAI et se faisait couper systématiquement.

**Deux corrections à ce que j'avais annoncé :**

- J'avais écrit « environ un appel sur trois mal formé ». Le chiffre datait
  d'avant la clarification du contrat de sortie ; la mesure donne **1 sur 5**, et
  la boucle redemande le jugement dans ce cas.
- J'avais conclu « Claude ne sait pas juger » sur un 0/5. C'était le plafond, pas
  le modèle : à 14 000 jetons il rend **3/3** verdicts exploitables et cohérents.
  Il reste 25 fois plus cher qu'OpenAI pour ce travail.

Gemini ignore par ailleurs la consigne de ne pas compter les objets : son seul
constat bloquant reprochait « 5 souris d'agneau » dans un panneau.

**A14 est close, autrement que prévu.** Sonnet 5 ne tronque plus l'article
(11 698 jetons sous le plafond, `end_turn`) : le plafond corrigé et l'élagage des
prompts ont suffi. Mais il écrit désormais trop **court** — 2 420 mots pour
2 800 exigés — à 0,1396 $ contre 0,0092 $ pour OpenAI. Le diagnostic initial,
« il écrit beaucoup trop long », était faux.

## Version 0.2.63

**Le juge traite désormais l'absence et l'invention de façon opposée**
(directive du 21/09), et c'est la bonne asymétrie.

- **Ce qui manque — indulgent.** Seuls les ingrédients *principaux* doivent être
  visibles. Tout autre ingrédient peut être invisible sans que ce soit un
  constat : il peut être dissous dans la sauce, enfoui sous une couche, déjà
  absorbé ou hors cadre. Le comptage reste ignoré.
- **Ce qui est ajouté — strict.** Tout élément comestible visible et absent de la
  liste canonique est **bloquant**, si discret soit-il : un brin de romarin, une
  feuille de laurier, un piment, un quartier de citron, une cuillerée de crème.
  Un lecteur qui suit la recette ne produira pas cette assiette.
- **Le doute penche vers le blocage**, à l'inverse du reste du contrôle : un
  ingrédient inventé trompe celui qui cuisine.

Mesuré sur deux plats. Souris d'agneau : des morceaux de tomate absents de la
recette — bloqué, corrigé, approuvé en un tirage (0,0721 $). Poulet yassa : une
douzaine de morceaux orange lus comme des carottes alors que la recette ne
contient qu'**un** habanero — bloqué, corrigé, approuvé en un tirage (0,0734 $).
La reprise a remplacé la douzaine par un seul habanero entier, exactement ce que
la liste prévoit.

**Un verdict mal formé n'est plus pris pour un refus.** `gpt-5.6-luna` ferme
parfois l'objet racine trop tôt et laisse les verdicts d'images en dehors, ce
qui se lisait comme « refusé, aucun constat » — et déclenchait une régénération
d'images contre une décision que personne n'avait prise. Le laboratoire
redemande le jugement sans toucher aux images. Observé sur environ un appel sur
trois ; à surveiller.

## Version 0.2.62

**Les neuf prompts sont désormais des gabarits compilés depuis les réglages**,
et plus aucun ne code en dur ce qu'un réglage contrôle. La langue de sortie,
jusque-là écrite « French » dans cinq prompts, vient de `site_language` :
basculer le site en espagnol bascule la recherche, la recette, l'article, la
relecture, la correction, les deux images et le contrôle final.

- `tools/promote-prompts.php` compile chaque gabarit et l'écrit dans les
  réglages qui alimentent la table `prompts`.
- `tests/test-prompt-templates.php` vérifie qu'aucun placeholder ne survit,
  qu'aucune langue n'est codée en dur, et surtout **que ce qui est livré est
  exactement ce que le gabarit compile**. La dérive entre le laboratoire et la
  production devient impossible : le plugin ne peut plus exécuter un prompt que
  personne n'a mesuré.

**Les fiches d'essai portent enfin de vraies observations.** Quatre des cinq
citaient `example.test` : aucune photographie n'avait jamais été analysée pour
elles, et toute mesure d'apparence portait sur une amorce inventée. Les cinq
sont rejouées sur de vraies sources — `provenceweb.fr`, `ptitchef.com`,
`commons.wikimedia.org`, `thekitchn.com`, `normandie-tourisme.fr` — avec 1 à 3
photographies téléchargées et analysées octet par octet.

Trouvé en chemin : le CDN de Food Network refuse nos requêtes (HTTP 403), et une
seule référence citée suffisait alors à nous laisser sans aucune observation. La
recherche doit maintenant citer **trois** photographies sur **trois domaines
différents** ; un refus ne rend plus aveugle. Nous ne contournons pas ce refus :
un serveur qui dit non a dit non.

## Version 0.2.61

L'intégration continue ne teste plus PHP 7.4. La matrice se limite à PHP 8.1 et
8.3, et les deux documents qui annonçaient encore 7.4 sont corrigés : un dépôt
ne doit pas revendiquer une compatibilité qu'il ne vérifie plus.

Le rapport HTML a par ailleurs été revu :

- **Sections dépliables.** Photographies analysées, dossier de recherche,
  recette, métadonnées SEO, relecture et données brutes s'ouvrent à la demande.
  La page se lit court et se creuse où le lecteur veut.
- **Constats mineurs visibles.** Ils étaient noyés dans un bloc JSON ; ils
  forment maintenant des fiches au même titre que les bloquants, avec la phrase
  en cause, ce qui ne va pas et la correction proposée — ce sont précisément les
  arbitrages qui reviennent à l'éditeur.
- **Responsive corrigé.** Le tableau des étapes forçait une largeur minimale de
  760 px ; sur mobile il s'empile en fiches, chaque valeur portant son intitulé.
  Rendu vérifié dans un vrai navigateur avant envoi.
- Les verdicts s'affichent en français (`Conforme`, `Réserves`, `Non conforme`)
  au lieu des valeurs brutes du modèle.

## Version 0.2.60

Passage complet rejoué avec le code courant, **approuvé sans constat bloquant**,
pour 0,1137 $ et environ quatre minutes de temps fournisseur.

**Le rapport suit l'ordre d'exécution** et prouve la provenance : brief de
l'éditeur, photographies réelles trouvées et analysées, recherche, recette,
article, SEO, relecture, visuels, approbation. La section 2 liste chaque
photographie citée avec sa page source et son fichier, dit si elle a été
analysée, et montre ce que la vision en a lu. Aucune n'est republiée.

**Élagage des prompts, partout où il réduit le coût** (directive du 21/09) :

| Appel | Avant | Après |
|---|---:|---:|
| Collage Facebook | 33 091 car. — *refusé par le fournisseur* | 20 711 car. |
| Image à la une | 0,0427 $ | **0,0266 $** |
| Collage Facebook | 0,0443 $ | **0,0326 $** |
| Recette canonique | 22 792 car. | 16 614 car. |
| Article | 35 296 car. | 29 118 car. |

Le collage dépassait la limite de 32 000 caractères du fournisseur et échouait
en HTTP 400 : on envoyait tout le dossier de recherche à un modèle d'image, dont
des températures, des URL et des règles d'hygiène qui ne changent aucune photo.
Les étapes texte perdent de la même façon `originality_notes`,
`visual_references` et `visual_observations` — l'apparence leur arrive distillée
par le brief visuel. Gain réel : environ 27 % sur les images, où les jetons
d'entrée sont facturés 5 $/M.

**Trois défauts trouvés en chemin :**

- `research_max_output_tokens` livré à 4 000 tronquait la recherche dès qu'on lui
  demandait aussi les substitutions (5 567 jetons nécessaires). Quatrième
  plafond trop bas — le laboratoire affiche désormais `!! TRUNCATED` dès qu'une
  réponse s'arrête exactement sur son plafond.
- Le prompt image dépassait la limite du fournisseur ; il échoue maintenant chez
  nous, où la cause est lisible.
- L'article inventait des substitutions et des gestes qu'aucune source ne
  documente — cinq constats bloquants. Il ne peut plus proposer qu'un
  remplacement attesté, et doit écrire franchement que les sources n'en
  documentent aucun le cas échéant. Constats bloquants : **5 → 0**.

## Version 0.2.59

Le rapport omettait l'étape d'approbation finale — celle qui décide — et ses
libellés ne se rattachaient à aucune commande : « Relecture » et « Revue
qualité » pouvaient désigner l'une ou l'autre étape.

- Chaque ligne du tableau porte désormais le nom de l'étape qui l'a produite :
  `Correction du français (proofread)`, `Revue éditoriale (review)`,
  `Vérification des faits (fact_check)`. Le tableau se vérifie ligne à ligne
  contre les commandes exécutées.
- L'approbation finale apparaît en neuvième ligne et dans sa propre section :
  décision, verdict par artefact, réalisme jugé à part pour les images, et les
  constats.

## Version 0.2.58

Premier passage complet sur une recette inédite — **souris d'agneau au four à
chaleur tournante, oignons grelots et ail confit** — et premier passage avec de
vraies photographies analysées, pas des amorces.

| Étape | Temps | Coût | Résultat |
|---|---:|---:|---|
| Recherche (2 photos réelles analysées) | 39,8 s | 0,0144 $ | 10/10 |
| Recette canonique | 22 s | 0,0038 $ | 4/4 |
| Article 3 413 mots | 47 s | 0,0095 $ | 10/10, qualité 98/100 |
| Image mise en avant | 10 s | 0,0373 $ | — |
| Collage Facebook | 13 s | 0,0428 $ | — |
| Relecture, fact-check, correction | 62 s | 0,0171 $ | 3/3, 5/5, 6/6 |
| Approbation finale | 22 s | 0,0055 $ | **10/10 — APPROUVÉ** |

Sources réelles : `greatbritishchefs.com`, deux photographies téléchargées et
analysées octet par octet. La mise en avant reprend ce qu'elles montrent — bol,
os saillants, sauce brune et luisante.

**Le juge applique désormais deux barèmes** (directive du 21/09), et c'est
délibéré : une photographie est une impression, l'article est ce que le lecteur
cuisine.

- **Images** : le réalisme photographique est la porte ; seuls les ingrédients
  *principaux* décident si c'est le bon plat ; herbe, gousse épluchée, comptage,
  vaisselle et cadrage deviennent mineurs ; le doute se résout vers la
  publication. Quatre calibrages antérieurs avaient chacun dépassé la cible.
- **Recette et article** : tout ingrédient, toute étape, toute durée que la
  recherche ne documente pas est bloquant, de même que tout ce qui la contredit.
  Le doute se résout vers le refus. Aucun échange entre les deux : de belles
  images n'excusent pas un ingrédient non sourcé.

Mesuré immédiatement : sur le même passage, les images tombent à deux constats
mineurs tandis que l'article récolte sept constats bloquants — substitutions,
accompagnements et critères d'achat qu'aucune source ne documente. La section
« substitutions » étant exigée par le plan de l'article, la recherche doit
maintenant collecter les substitutions et les accompagnements attestés.

- `tools/prune-runs.php` applique la rétention convenue : dernier article,
  dernière recette, dernière image mise en avant, **tous** les collages.
- `tools/cost-history.jsonl` conserve **tout** l'historique de coût — 152 appels,
  5,18 $ mesurés — avant toute suppression. Les tirages se régénèrent, les
  mesures non.
- Les rapports générés quittent le dépôt (8,8 Mo d'images encodées) ;
  `tools/reports/` est ignoré.
- Trois défauts trouvés en chemin : un `editor_input` mal formé était transmis
  tel quel et la recherche répondait, à juste titre, qu'on ne lui donnait aucun
  plat ; le score de recherche validait un paquet à zéro fait ; une image
  régénérée n'enregistrait jamais son coût.

## Version 0.2.57

**Ce que la recherche a lu et ce qu'elle a vu alimentent désormais toutes les
étapes**, et sous une forme exploitable : un *brief visuel* dérivé de la recette
et des observations, à la place du JSON brut qu'on jetait au modèle.

Chaque ligne du brief existe parce qu'une image réelle a échoué dessus :

- **Comptes exacts** tirés des quantités (`1 rouleau`, `6 pommes`, `2 œufs`),
  bornés à la mise en place seule.
- **Ustensiles** tirés de `equipment` : le moule doit être le même partout ; une
  planche ou un saladier nécessaires à une étape restent permis, un support qui
  change la présentation finale ne l'est pas.
- **Échelle et cuisson** : portions et durée réelles, « pas plus foncé pour
  l'effet ».
- **Apparence observée** dans de vraies photographies.
- **Une seule présentation de service, nommée**, partagée par les deux images.

Mesuré sur la tarte normande, huit collages puis quatre paires :

| Défaut | Avant | Après |
|---|---|---|
| Panneaux dans le désordre | 3 refus sur 5 | **aucun** |
| Contenant différent entre les deux images | quasi systématique | **aucun** une fois la présentation nommée |
| Ustensiles étrangers | plusieurs | **aucun** |

Décrire le plat ne suffisait pas : deux appels lisant « entière, vue de trois
quarts » choisissaient encore l'un une assiette, l'autre un moule. Le contenant
devait être **nommé**, pas décrit.

Le compte d'ingrédients passe de bloquant à mineur : un lecteur prend les
quantités dans la liste, pas en comptant les pommes sur une photo — et les
modèles d'image ne comptent pas de façon fiable.

`lab_observed_appearance()` alimente la recette canonique, `lab_visual_brief()`
l'article, les deux images et le contrôle final.

**Limite connue.** Les observations des fiches d'essai sont des amorces
pointant vers `example.test` : aucune vraie photographie n'a jamais été
analysée pour elles. Les refus de couleur restants s'appuient donc sur une
référence d'une ligne, inventée. Prochaine étape : rejouer la recherche avec le
scraping d'images réel sur les deux fiches.

## Version 0.2.56

Le collage Facebook est repassé au banc d'essai avec l'étape d'approbation, et
le résultat contredit l'hypothèse de départ.

**La qualité n'est pas le levier.** L'image mise en avant est jugée `good`,
verdict et réalisme, à **toutes les qualités, `low` comprise** : monter sa
qualité n'achète aucune amélioration détectable. Le collage échoue en revanche
aux deux qualités, et toujours des deux mêmes façons — panneaux dans le
désordre, et vaisselle de service qui n'apparaît nulle part ailleurs. En `high`,
à 2,5× le prix, un tirage sur deux est encore refusé.

Une partie de la cause était dans notre propre prompt : les rôles génériques
plaçaient « whisking, mixing » en panneau 2, ce qui, pour une tarte, fait battre
l'appareil avant même le fonçage de la pâte — exactement l'erreur relevée deux
fois par le juge. Les rôles sont désormais subordonnés à l'ordre canonique de la
recette, et le panneau 6 doit servir le plat dans le contenant décrit par les
observations. Le `medium` passe de 0/3 à 1/3 approuvé : un progrès, pas une
solution.

Les huit tirages, leurs coûts et leurs verdicts sont consignés dans
[`.claude/docs/LAB-RESULTS.md`](.claude/docs/LAB-RESULTS.md), avec les deux routes chiffrées
pour la suite — réessayer jusqu'à approbation (~0,077 $ le collage accepté) ou
générer six panneaux séparés et composer la grille nous-mêmes (0,062 $ en `low`,
0,106 $ en `medium`, composition gratuite et ordre garanti par construction).

## Version 0.2.55

**La qualité se règle image par image, `medium` par défaut pour les deux.**
Les deux visuels n'ont ni le même coût ni le même rôle : une seule case
« Qualité OpenAI » les traitait pourtant ensemble, et elle était livrée sur
`low`.

Mesuré le 20/09/2026, `gpt-image-2.5-flare`, image principale 1024×1024 :

| Qualité | Jetons image | Temps | Coût |
|---|---:|---:|---:|
| `low` | 196 | 8,7 s | 0,0104 $ |
| `medium` | 439 | 10,1 s | 0,0177 $ |
| `high` | 1 756 | 18,0 s | 0,0572 $ |

Le rapport livré était généré en `high` : 0,0708 $ pour l'image principale et
0,0662 $ pour le collage, soit 0,137 $ — **74 % du coût total de 0,1856 $**. En
`medium`, les deux ensemble reviennent à 0,038 $.

- Un sélecteur par image dans l'écran de réglages, avec les coûts mesurés
  affichés sous le champ.
- L'estimation budgétaire lit désormais la qualité propre à chaque image ; elle
  appliquait une valeur unique aux deux, donc elle se trompait dès que les deux
  différaient.
- L'ancienne clé `image_quality` reste lue en repli : un site déjà configuré
  garde son choix.
- Le laboratoire lit la valeur livrée au lieu d'un `medium` codé en dur, sinon
  il ne mesure pas ce qui part en production.

## Version 0.2.54

**L'étape d'approbation finale existe.** C'était la seule des cinq étapes
convenues sans rien derrière : le plugin possédait `prompt_image_review`, jamais
mesuré, qui jugeait une image à la fois. Dans le rapport livré, les visuels
étaient validés « contrôle visuel manuel », à l'œil, et les corrections demandées
par les relectures étaient appliquées à la main.

Un seul appel voit désormais l'article et les deux images **ensemble** — le seul
moment où les trois peuvent être confrontés. Il rend un verdict par artefact, le
réalisme jugé à part de la fidélité, le nombre de panneaux réellement comptés,
et des constats citant la phrase exacte en faute.

Réglé contre de vrais artefacts, quatre itérations mesurées :

1. Le réalisme et la fidélité étaient dans la même règle : le juge validait une
   image « bonne » tout en décrivant un ingrédient absent. Séparés.
2. Trop strict ensuite : une plante en pot du décor était signalée comme
   ingrédient. Le décor n'est plus un constat.
3. Trop strict encore : le riz blanc bloquait, alors que le yassa se sert avec du
   riz et que la recherche le confirme. Un accompagnement documenté n'est plus un
   constat.
4. Tout devenait « bloquant » — 17 constats bloquants sur une tarte. Une porte qui
   ne s'ouvre jamais ne sert à rien. La sévérité est maintenant définie : 17
   constats sont devenus 3, dont 2 bloquants.

Résultat sur deux plats, `gpt-5.6-luna`, 9/9 à chaque fois, ~22s et $0.005 :
refus motivé des deux côtés — une garniture verte absente de la recette sur le
dernier panneau du collage, et une rupture de vaisselle entre le collage et
l'image mise en avant. Aucun faux positif sur le riz, le laurier, le piment ni
le décor.

- `approval_max_output_tokens` (6 000) : le premier essai s'est terminé en
  `incomplete` à 3 000.
- Le score refusait de sanctionner une réponse illisible : trois contrôles
  passaient sur zéro constat. Une réponse illisible échoue désormais partout.

## Version 0.2.53

La recette canonique fournit enfin les données structurées Google attendues, et
une liste d'ingrédients avec laquelle on peut réellement cuisiner.

- **`recipe_category` et `description`** sont produits et enregistrés : Google
  lit le premier comme cours du repas, le second comme phrase affichée dans les
  résultats de recherche. Aucun des deux n'existait.
- **`total_minutes`** était produit depuis toujours mais absent de
  `integration_mapping` : il n'atteignait jamais l'article. Il est désormais
  mappé et vérifié.
- **`canonical_max_output_tokens` passe de 2 600 à 4 500.** Le plafond était
  déjà trop bas avant cet ajout : la recette du rapport livré consommait 3 260
  jetons de sortie, et un premier essai s'est terminé en `incomplete` à 2 600
  exactement.
- **Liste d'ingrédients cuisinable.** La règle « n'invente rien » supprimait les
  ingrédients de base : un poulet yassa revenait avec quatre ingrédients, sans
  huile ni sel. La recette doit maintenant permettre de cuisiner sans deviner —
  matière grasse, sel, poivre, eau ou bouillon quand la méthode les exige — tout
  en continuant d'interdire l'invention d'un ingrédient distinctif. Mesuré sur
  `gpt-5.6-luna` : 4 ingrédients et 5 étapes avant, 7 et 14 après ; la tarte
  normande passe de 4 à 9 ingrédients. Les deux fiches passent 4/4.
- La notation `[string]` du contrat était ambiguë et le modèle renvoyait
  `keywords` en une seule chaîne séparée par des virgules. Le contrat dit
  désormais « array of strings », et le contrôle accepte les deux formes, que le
  publisher sait déjà lire.

## Version 0.2.52

La recherche devient la source d'une **apparence vérifiée** plutôt que devinée.
Elle cite des photographies réelles du plat — jamais générées, retouchées ni
composées — et le laboratoire télécharge chaque image citée pour l'analyser
octet par octet ; ce que la vision y observe descend ensuite dans la recette
canonique, l'article et les deux prompts d'image.

**Aucune photographie n'est republiée.** L'image sert à regarder, pas à
illustrer : les observations remplacent la photo, qui reste à son auteur. Une
observation ne peut établir qu'une apparence — jamais un ingrédient caché, une
quantité ni une étape de préparation.

- `prompt_research` livré correspond enfin au prompt mesuré au laboratoire : il
  exige la provenance (`image_url` et `source_url` en HTTPS) et interdit de
  deviner l'apparence depuis un extrait de recherche.
- `tests/test-visual-reference.php` vérifie que la recette, l'article et la
  relecture reçoivent encore le dossier de recherche. Une étape qui cesse de le
  recevoir n'échoue pas : elle invente une apparence sans rien signaler.
- `docs/LAB-RESULTS.md` consigne les 51 appels réels déjà mesurés — modèle,
  durée, coût, score, cause d'arrêt — pour que chaque chiffre soit reproductible
  ou contestable.
- `research_facts_max` et `research_references_max` deviennent réglables.

Une approche concurrente, mesurée puis écartée le même jour, faisait décrire les
photographies au modèle de mémoire, en six facettes nommées. Elle obtenait 8/8
sur deux plats et amenait l'article à 10/10, `visual_final_notes` compris — la
seule vérification qui échouait encore sur les neuf cellules d'article. Lire les
octets reste une preuve plus solide qu'un modèle décrivant une page consultée.
Le raisonnement est conservé dans `docs/BUILD-CHECKLIST.md`, tâche A3.

## Version 0.2.51

Une réponse coupée à `max_tokens` se termine au milieu d'un caractère UTF-8, et
ce seul caractère cassé fait échouer `json_encode` sur toute la chaîne : Sonnet 5
a facturé 14 500 jetons de sortie le 20/09/2026 et la réponse a été enregistrée
vide. `MSRWA_Json::valid_utf8` nettoie la fin d'une réponse tronquée avant
lecture et avant enregistrement, dans le moteur comme au laboratoire.

**Ce que cela ne corrige pas :** sur l'étape article, Sonnet 5 dépasse encore le
plafond de 14 500 jetons. Le raisonnement étendu n'est pas en cause (vérifié :
`thinking_tokens` à 0 par défaut) ; le modèle écrit simplement beaucoup plus
long. À trancher avant la phase B.

## Version 0.2.50

Corrige deux pertes de résultat mesurées sur de vraies réponses de modèles, le
20/09/2026.

- **Lecture du JSON.** `MSRWA_Json` répare ce qu'un modèle écrit réellement :
  une phrase avant l'objet (Opus 5 en recherche web), un retour à la ligne brut
  laissé dans une chaîne de 18 Ko (Sonnet 5 sur `content_html`), une clôture
  Markdown. Une réponse tronquée reste une erreur. Les deux appels perdus du
  laboratoire passent désormais : recherche 0/5 → 5/5.
- **Plafond de sortie.** 8 000 jetons ne suffisent pas pour 2 800 mots : la
  mesure sur quinze articles réels donne 2,0 jetons par mot au mieux et 3,97 sur
  un modèle qui facture son raisonnement. Le plafond se déduit désormais du
  nombre de mots visé (`MSRWA_Cost::output_budget`), et l'estimation de coût
  utilise la même fourchette mesurée au lieu de 1,6 jeton par mot.
- `quality_max_words` passe de 2 400 à 3 600 : la valeur livrée était inférieure
  au minimum exigé de 2 800.

## Version 0.2.49

- Les prompts deviennent des gabarits compilés depuis les réglages (`MSRWA_Prompt`) : nombre de mots, découpe en une ou deux pages, titre d’ouverture de la page 2, plan de sections modifiable, tailles et format d’images, langue du site, nombre de questions FAQ et limite de liens internes. Modifier un réglage modifie le prompt, il n’est plus réécrit à la main.
- Le laboratoire compile les gabarits avec la même classe que le moteur : ce qui est validé en laboratoire est exactement ce que le plugin enverra.
- Nouveaux réglages : `site_language`, `article_page2_heading`, `required_sections`, `facebook_collage_steps`.
- Laboratoire multi-fournisseurs : adaptateurs OpenAI, Gemini et Anthropic, table des tarifs publiés par fournisseur et trois paliers de modèles (bas, moyen, haut) pour comparer qualité, durée et coût sur le même prompt.
- Prompts rédigés en anglais pour une sortie en français, et complétés : recherche, recette canonique, article, relecture, vérification des faits, correction orthographique, images mise en avant et Facebook.

## Version 0.2.48

- Prompt article remplacé par une version prouvée en laboratoire : article complet en un seul appel, deux pages séparées par `<!--nextpage-->`, plan imposé en douze sections, 2800 mots minimum, champs SEO et légende Facebook dans la même réponse. Validé 10 contrôles sur 10 sur deux cuisines différentes.
- Correction d’un défaut de rédaction : le prompt précédent produisait un français sans accents. La densité d’accents passe de 1,0 à 33 pour mille caractères.
- `quality_min_words` porté à 2800 pour que la grille qualité corresponde au contrat rédactionnel.
- Laboratoire de prompts (`tools/`) : exécution d’une étape contre l’API réelle sans WordPress, notation par la grille qualité du plugin, génération d’images de contrôle, coût et durée relevés à chaque essai.

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
- Suite de tests hors ligne outillée : `php tests/run.php` (lint + tests), harnais partagé `tests/bootstrap.php`, double `$wpdb` enregistreur, intégration continue PHP 8.1/8.3.
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
