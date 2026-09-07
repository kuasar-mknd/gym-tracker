import { readFileSync, statSync } from 'node:fs'

/**
 * Ce que l'installation de la PWA télécharge d'un coup.
 *
 * Le glob prenait tout ce que Vite écrit : 142 fichiers, 1 249 Kio, dont le
 * graphique et le morceau de chaque page jamais visitée (#1814). La coquille
 * — l'entrée, la CSS, Vue, les polices latines, l'accueil et la séance — en
 * pèse 572. Un glob élargi par mégarde referait grossir l'installation sans
 * que rien ne le dise.
 */
const MAX_ENTREES = 20
const MAX_KIO = 700

const worker = readFileSync('public/sw.js', 'utf8')
const urls = [...worker.matchAll(/"url":"([^"]+)"/g)].map((trouve) => trouve[1])

if (urls.length === 0) {
    console.error('Aucune entrée de precache trouvée dans public/sw.js : le worker a-t-il été construit ?')
    process.exit(1)
}

const octets = urls.reduce((total, url) => {
    try {
        return total + statSync(`public${url}`).size
    } catch {
        return total
    }
}, 0)

const kio = Math.round(octets / 1024)

console.log(`precache : ${urls.length} entrées, ${kio} Kio`)

if (urls.length > MAX_ENTREES || kio > MAX_KIO) {
    console.error(`Le precache dépasse la coquille (${MAX_ENTREES} entrées, ${MAX_KIO} Kio).`)
    console.error(urls.join('\n'))
    process.exit(1)
}
