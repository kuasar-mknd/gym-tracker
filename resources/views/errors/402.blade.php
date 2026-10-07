{{--
    Le framework fournit sa propre vue 402, que Laravel trouve avant le repli
    `4xx` : sans ce fichier, le code rendait la page anglaise. L'application
    ne demande aucun paiement, d'où la page générique des codes 4xx.
--}}
@include('errors.4xx')
