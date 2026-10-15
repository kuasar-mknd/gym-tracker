import { readFileSync, statSync } from 'node:fs'

/**
 * Ce que l'installation de la PWA télécharge d'un coup.
 *
 * Le glob prenait tout ce que Vite écrit : 142 fichiers, 1 249 Kio, dont le
 * graphique et le morceau de chaque page jamais visitée (#1814). La coquille
 * — l'entrée, la CSS, Vue, les polices latines, l'accueil et la séance — en
 * pèse 572. Un glob élargi par mégarde referait grossir l'installation sans
 * que rien ne le dise. Depuis #2048, la police des icônes et les dix icônes
 * de public/ y entrent aussi : 23 entrées, 624 Kio.
 *
 * Chaque entrée doit aussi être un fichier de public/ que le serveur sert tel
 * quel, jamais un script que PHP exécuterait : une seule en 404 et Workbox
 * annule toute l'installation, ce qui a laissé la production sans worker de la
 * 1.5.0 à la 1.5.8 (#1683). `ServiceWorkerAssetsTest` tient la même règle côté
 * PHP. La CI lance ce script deux fois : sur la construction, puis sur les
 * actifs que les tests PHP téléchargent.
 */
const MAX_ENTREES = 25
const MAX_KIO = 700

/**
 * La taille du fichier de public/ qu'une entrée désigne, ou `null` quand le
 * serveur ne le servirait pas tel quel.
 *
 * @param {string} url
 * @returns {number|null}
 */
const tailleDuFichierServi = (url) => {
    const chemin = url.split('?')[0]

    if (chemin.endsWith('.php')) {
        return null
    }

    try {
        const fichier = statSync(`public${chemin}`)

        return fichier.isFile() ? fichier.size : null
    } catch {
        return null
    }
}

const worker = readFileSync('public/sw.js', 'utf8')
const urls = [...worker.matchAll(/"url":"([^"]+)"/g)].map((trouve) => trouve[1])

if (urls.length === 0) {
    console.error('Aucune entrée de precache trouvée dans public/sw.js : le worker a-t-il été construit ?')
    process.exit(1)
}

const muettes = urls.filter((url) => tailleDuFichierServi(url) === null)

if (muettes.length > 0) {
    console.error(
        "Des entrées du precache ne sont pas un fichier de public/ servi tel quel : l'installation échouerait.",
    )
    console.error(muettes.join('\n'))
    process.exit(1)
}

const kio = Math.round(urls.reduce((total, url) => total + tailleDuFichierServi(url), 0) / 1024)

console.log(`precache : ${urls.length} entrées, ${kio} Kio`)

if (urls.length > MAX_ENTREES || kio > MAX_KIO) {
    console.error(`Le precache dépasse la coquille (${MAX_ENTREES} entrées, ${MAX_KIO} Kio).`)
    console.error(urls.join('\n'))
    process.exit(1)
}
