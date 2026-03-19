---
title: Ordre de configuration
order: 1
---

Cette page explique dans quel ordre configurer les donnees pour ne pas vous bloquer plus tard.

## Ordre recommande

1. categories d'ingredients,
2. ingredients,
3. fournisseurs,
4. ingredients references fournisseur,
5. categories de produits,
6. lignes de production et jours feries,
7. types de produit,
8. formules,
9. modeles QC et modeles de taches,
10. produits,
11. productions ou vagues.

## Pourquoi cet ordre est important

- Un produit depend de son type de produit.
- Une commande fournisseur utile depend d'un ingredient deja reference.
- Une production se planifie beaucoup mieux si les lignes, templates et regles QC sont deja en place.

## Erreurs frequentes

- Creer un produit avant son type de produit.
- Creer une production sans formule ou sans cadre de planning.
- Commander un ingredient qui n'est pas encore lie au bon fournisseur.
