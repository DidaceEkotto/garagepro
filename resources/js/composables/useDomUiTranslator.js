/**
 * Traducteur automatique des éléments statiques de l'interface (UI uniquement).
 *
 * Règle de sécurité absolue :
 * - Ne traduit QUE les textes qui correspondent EXACTEMENT à une clé statique
 *   du dictionnaire d'interface (`ui_fr_to_locale`).
 * - Les données provenant de la base de données (noms de clients, plaques,
 *   marques/modèles, notes, numéros de factures, titres produits, etc.) ne figurent
 *   jamais dans ce dictionnaire et ne sont donc JAMAIS traduites.
 * - Tout conteneur marqué `data-no-translate` ou `translate="no"` est ignoré.
 */

const originalTextMap = new WeakMap();
const originalAttrMap = new WeakMap();
const TRANSLATABLE_ATTRS = ['placeholder', 'title', 'aria-label'];
const SKIP_TAGS = new Set(['SCRIPT', 'STYLE', 'CODE', 'PRE', 'TEXTAREA', 'NOSCRIPT']);

let currentLocale = 'fr';
let currentDict = null;
let dictPromise = null;
let loadedDictionaries = {};
let observer = null;
let scheduled = false;

function normalizeText(str) {
    return String(str || '').replace(/\s+/g, ' ').trim();
}

function shouldSkipElement(el) {
    if (!el || el.nodeType !== 1) return false;
    if (SKIP_TAGS.has(el.tagName)) return true;
    if (el.getAttribute('translate') === 'no' || el.hasAttribute('data-no-translate')) return true;
    return false;
}

function isInsideSkipped(node) {
    let parent = node.nodeType === 1 ? node : node.parentElement;
    while (parent && parent !== document.body) {
        if (shouldSkipElement(parent)) return true;
        parent = parent.parentElement;
    }
    return false;
}

function translateTextNode(node) {
    if (!node || node.nodeType !== 3) return;
    if (isInsideSkipped(node)) return;

    const raw = node.nodeValue || '';
    const trimmed = normalizeText(raw);
    if (!trimmed) return;

    // Enregistrer le texte source français original la première fois
    let orig = originalTextMap.get(node);
    if (orig === undefined) {
        orig = raw;
        originalTextMap.set(node, orig);
    } else if (currentLocale !== 'fr') {
        // Si Vue a réactivement changé le texte vers une nouvelle valeur différente de notre traduction,
        // on met à jour la source originale.
        const origTrimmed = normalizeText(orig);
        const expectedTranslated = currentDict?.[origTrimmed];
        if (trimmed !== origTrimmed && trimmed !== expectedTranslated) {
            orig = raw;
            originalTextMap.set(node, orig);
        }
    }

    const origTrimmed = normalizeText(orig);

    if (currentLocale === 'fr' || !currentDict) {
        if (node.nodeValue !== orig) {
            node.nodeValue = orig;
        }
        return;
    }

    const translated = currentDict[origTrimmed];
    if (translated && translated !== origTrimmed) {
        // Préserver les espaces de début/fin éventuels autour du texte
        const lead = orig.match(/^\s*/)?.[0] || '';
        const trail = orig.match(/\s*$/)?.[0] || '';
        const nextVal = `${lead}${translated}${trail}`;
        if (node.nodeValue !== nextVal) {
            node.nodeValue = nextVal;
        }
    }
}

function translateElementAttrs(el) {
    if (!el || el.nodeType !== 1 || shouldSkipElement(el) || isInsideSkipped(el)) return;

    let saved = originalAttrMap.get(el);
    if (!saved) {
        saved = {};
        originalAttrMap.set(el, saved);
    }

    for (const attr of TRANSLATABLE_ATTRS) {
        if (!el.hasAttribute(attr)) continue;
        const val = el.getAttribute(attr) || '';
        const trimmed = normalizeText(val);
        if (!trimmed) continue;

        if (saved[attr] === undefined) {
            saved[attr] = val;
        }

        const orig = saved[attr];
        const origTrimmed = normalizeText(orig);

        if (currentLocale === 'fr' || !currentDict) {
            if (val !== orig) el.setAttribute(attr, orig);
            continue;
        }

        const translated = currentDict[origTrimmed];
        if (translated && val !== translated) {
            el.setAttribute(attr, translated);
        }
    }
}

function walkAndTranslate(root) {
    if (!root || typeof document === 'undefined') return;

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
    let node = walker.nextNode();
    while (node) {
        if (node.nodeType === 3) {
            translateTextNode(node);
        } else if (node.nodeType === 1) {
            translateElementAttrs(node);
        }
        node = walker.nextNode();
    }
}

function scheduleTranslate() {
    if (scheduled || typeof window === 'undefined') return;
    scheduled = true;
    requestAnimationFrame(() => {
        scheduled = false;
        if (document.body) {
            walkAndTranslate(document.body);
        }
    });
}

async function loadLocaleDict(locale) {
    if (!locale || locale === 'fr') {
        currentDict = null;
        return;
    }
    if (loadedDictionaries[locale]) {
        currentDict = loadedDictionaries[locale];
        return;
    }

    try {
        // Chargement paresseux (code-splitting) par langue : 0 octet chargé pour les utilisateurs FR !
        const loaders = {
            en: () => import('./uiLocales/en.json'),
            es: () => import('./uiLocales/es.json'),
            pt: () => import('./uiLocales/pt.json'),
            ar: () => import('./uiLocales/ar.json'),
            zh: () => import('./uiLocales/zh.json'),
        };
        const loader = loaders[locale];
        if (!loader) {
            currentDict = null;
            return;
        }
        const mod = await loader();
        loadedDictionaries[locale] = mod.default || mod;
        if (currentLocale === locale) {
            currentDict = loadedDictionaries[locale];
        }
    } catch {
        currentDict = null;
    }
}

export async function applyUiLocale(locale) {
    const nextLocale = locale || 'fr';
    currentLocale = nextLocale;
    if (typeof document !== 'undefined') {
        document.documentElement.lang = nextLocale;
        document.documentElement.dir = nextLocale === 'ar' ? 'rtl' : 'ltr';
    }
    dictPromise = loadLocaleDict(nextLocale);
    await dictPromise;
    scheduleTranslate();
}

export function initDomUiTranslator(getInitialLocale) {
    if (typeof document === 'undefined') return;

    const initial = typeof getInitialLocale === 'function' ? getInitialLocale() : (document.documentElement.lang || 'fr');
    applyUiLocale(initial);

    if (!observer) {
        observer = new MutationObserver((mutations) => {
            if (currentLocale === 'fr' && !currentDict) return;
            for (const m of mutations) {
                if (m.type === 'childList' && m.addedNodes.length > 0) {
                    scheduleTranslate();
                    break;
                }
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
}
