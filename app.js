/**
 * Test di Cultura — frontend SPA (server-driven).
 * Le domande non sono mai scaricate in blocco dal client: vengono pescate dal server una pagina alla volta.
 */

const STORAGE_KEY = 'test-cultura-state-v2';
const NAME_KEY = 'test-cultura-name-v1';
const QUESTIONS_PER_PAGE = 5;
const RANGES = {
    facile:    { min: 5,  max: 25 },
    medio:     { min: 26, max: 50 },
    difficile: { min: 51, max: 75 },
    esperto:   { min: 76, max: 100 },
};
const LEVEL_ORDER = ['facile', 'medio', 'difficile', 'esperto'];

let MANIFEST = null;
let MATERIE_INDEX = null;
let state = null;

const app = document.getElementById('app');

// -------- Persistenza --------
function saveState() {
    if (state) localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}
function loadState() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch { return null; }
}
function clearState() {
    state = null;
    localStorage.removeItem(STORAGE_KEY);
}

// -------- Boot --------
async function boot() {
    try {
        const res = await fetch('data/manifest.json?v=1');
        MANIFEST = await res.json();
        MATERIE_INDEX = Object.fromEntries(MANIFEST.map(m => [m.nome, m]));
        const subtitle = document.getElementById('headerSubtitle');
        if (subtitle) {
            const totMaterie = MANIFEST.length;
            const totDomande = MANIFEST.reduce((s, m) => s + (m.domandeCount || 0), 0);
            subtitle.textContent = `Quiz adattivo basato su ${totMaterie} materie e ${totDomande.toLocaleString('it-IT')} domande`;
        }
    } catch (err) {
        app.innerHTML = `<div class="glass rounded-2xl p-6"><p class="text-rose-400">Errore caricamento manifest.</p></div>`;
        console.error(err);
        return;
    }
    const saved = loadState();
    if (saved && saved.phase === 'quiz' && Array.isArray(saved.questions)) {
        state = saved;
        renderQuiz();
    } else {
        renderSetup();
    }
}
boot();

// -------- Setup --------
function renderSetup() {
    clearState();
    app.innerHTML = '';
    const tpl = document.getElementById('tpl-setup').content.cloneNode(true);
    app.appendChild(tpl);

    const nameInput = document.getElementById('nameInput');
    nameInput.value = localStorage.getItem(NAME_KEY) || '';
    nameInput.addEventListener('input', () => {
        const v = nameInput.value.trim();
        if (v) localStorage.setItem(NAME_KEY, v);
    });

    setupLeaderboardTabs('setup', 'leaderboardList', 'leaderboardTotal', { defaultTab: 'adattiva' });

    const specSlider = document.getElementById('specSlider');
    const specValue = document.getElementById('specValue');

    const modeRadios = document.querySelectorAll('input[name="mode"]');
    const rangesField = document.getElementById('rangesField');
    modeRadios.forEach(r => r.addEventListener('change', () => {
        rangesField.classList.toggle('hidden', document.querySelector('input[name="mode"]:checked').value !== 'manuale');
    }));

    const sortedMaterie = [...MANIFEST].sort((a, b) => a.nome.localeCompare(b.nome, 'it'));

    const select = document.getElementById('excludeSelect');
    const chips = document.getElementById('excludedChips');
    const counter = document.getElementById('excludeCount');
    const excluded = [];

    const refreshSelect = () => {
        const maxSpec = parseInt(specSlider.value, 10);
        const visible = sortedMaterie.filter(m => m.specificita <= maxSpec);
        for (let i = excluded.length - 1; i >= 0; i--) {
            const nome = excluded[i];
            const m = MATERIE_INDEX[nome];
            if (!m || m.specificita > maxSpec) excluded.splice(i, 1);
        }
        select.innerHTML = '<option value="">— Aggiungi una materia da escludere —</option>';
        visible.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.nome;
            opt.textContent = `${m.nome} (spec ${m.specificita})`;
            opt.disabled = excluded.includes(m.nome);
            select.appendChild(opt);
        });
    };

    const renderChips = () => {
        chips.innerHTML = '';
        excluded.forEach(nome => {
            const chip = document.createElement('span');
            chip.className = 'chip inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-indigo-500/15 border border-indigo-400/30 text-xs';
            chip.innerHTML = `${escapeHtml(nome)}<button class="text-indigo-300 hover:text-white">×</button>`;
            chip.querySelector('button').addEventListener('click', () => {
                const i = excluded.indexOf(nome);
                if (i >= 0) excluded.splice(i, 1);
                renderChips();
                refreshSelect();
            });
            chips.appendChild(chip);
        });
        counter.textContent = `${excluded.length}/3`;
    };

    specSlider.addEventListener('input', () => {
        specValue.textContent = specSlider.value;
        refreshSelect();
        renderChips();
    });

    select.addEventListener('change', () => {
        const v = select.value;
        if (v && excluded.length < 3 && !excluded.includes(v)) {
            excluded.push(v);
            renderChips();
            refreshSelect();
        }
        select.value = '';
    });

    refreshSelect();
    renderChips();

    document.getElementById('startBtn').addEventListener('click', () => {
        const nome = nameInput.value.trim().replace(/\s+/g, ' ').slice(0, 30);
        if (!nome) {
            showSetupError('Inserisci il tuo nome per iniziare.');
            nameInput.focus();
            return;
        }
        const mode = document.querySelector('input[name="mode"]:checked').value;
        const answerType = document.querySelector('input[name="answerType"]:checked').value;
        const spec = parseInt(specSlider.value, 10);
        const qcount = parseInt(document.querySelector('input[name="qcount"]:checked').value, 10);
        let selectedRanges = [];
        if (mode === 'manuale') {
            selectedRanges = [...document.querySelectorAll('input[name="range"]:checked')].map(c => c.value);
            if (selectedRanges.length === 0) {
                showSetupError('Seleziona almeno un range di difficoltà.');
                return;
            }
        }
        localStorage.setItem(NAME_KEY, nome);
        startQuiz({ nome, mode, answerType, spec, qcount, excluded: [...excluded], selectedRanges });
    });
}
function showSetupError(msg) {
    const el = document.getElementById('setupError');
    el.textContent = msg;
    el.classList.remove('hidden');
}

// -------- Avvio quiz --------
async function startQuiz(cfg) {
    // Mostra loading durante il fetch del primo batch
    app.innerHTML = '';
    app.appendChild(document.getElementById('tpl-loading').content.cloneNode(true));
    const hint = document.getElementById('loadingHint');
    if (hint) hint.textContent = 'Sto preparando le domande…';

    state = {
        phase: 'quiz',
        cfg,
        questions: [],
        currentPage: 0,
        currentLevel: cfg.mode === 'adattiva' ? 'facile' : null,
        maxLevelReached: cfg.mode === 'adattiva' ? 'facile' : null,
        seenIds: [],
        saved: false,
    };

    try {
        const batch = await fetchBatch();
        if (!batch || batch.length === 0) {
            app.innerHTML = `<div class="glass rounded-2xl p-6"><p class="text-rose-400">Nessuna domanda disponibile con questa configurazione. Allarga la specificità o riduci le esclusioni.</p><button id="backBtn" class="mt-3 underline">Torna al setup</button></div>`;
            document.getElementById('backBtn').addEventListener('click', renderSetup);
            return;
        }
        state.questions.push(...batch);
        state.seenIds.push(...batch.map(q => q._id));
        saveState();
        renderQuiz();
    } catch (err) {
        console.error(err);
        app.innerHTML = `<div class="glass rounded-2xl p-6"><p class="text-rose-400">Errore di rete. Riprova.</p><button id="backBtn" class="mt-3 underline">Torna al setup</button></div>`;
        document.getElementById('backBtn').addEventListener('click', renderSetup);
    }
}

async function fetchBatch() {
    const payload = {
        cfg: {
            mode: state.cfg.mode,
            spec: state.cfg.spec,
            excluded: state.cfg.excluded,
            qcount: state.cfg.qcount,
            selectedRanges: state.cfg.selectedRanges,
            answerType: state.cfg.answerType,
        },
        seenIds: state.seenIds.slice(),
    };
    if (state.cfg.mode === 'adattiva') {
        payload.currentLevel = state.currentLevel || 'facile';
    }
    const res = await fetch('api/get-batch.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    if (!res.ok) throw new Error('get-batch HTTP ' + res.status);
    const data = await res.json();
    if (!data || !Array.isArray(data.batch)) throw new Error('invalid response');
    return data.batch;
}

// -------- Render quiz --------
function renderQuiz() {
    app.innerHTML = '';
    const tpl = document.getElementById('tpl-quiz').content.cloneNode(true);
    app.appendChild(tpl);

    const pageStart = state.currentPage * QUESTIONS_PER_PAGE;
    const pageEnd = pageStart + QUESTIONS_PER_PAGE;
    const pageQs = state.questions.slice(pageStart, pageEnd);

    const totalPages = Math.ceil(state.cfg.qcount / QUESTIONS_PER_PAGE);
    document.getElementById('quizProgressLabel').textContent = `Pagina ${state.currentPage + 1} di ${totalPages}`;
    document.getElementById('quizCounterLabel').textContent = `${pageEnd} / ${state.cfg.qcount} domande`;
    document.getElementById('quizProgressBar').style.width = `${(state.currentPage / totalPages) * 100}%`;

    const cards = document.getElementById('quizCards');
    pageQs.forEach((q, idx) => {
        const absIdx = pageStart + idx;
        const card = document.createElement('div');
        card.className = 'glass rounded-2xl p-5 ring-grad fadein';
        const diffColor = difficultyColor(q.difficolta);

        let answerHtml;
        if (Array.isArray(q.opzioni) && q.opzioni.length >= 2) {
            const selected = q.risposta_utente || '';
            const optsHtml = q.opzioni.map((opt, oi) => {
                const checked = selected === opt ? 'checked' : '';
                const id = `q${absIdx}_o${oi}`;
                return `
                <label class="cursor-pointer block">
                    <input type="radio" name="q${absIdx}" value="${escapeHtml(opt)}" id="${id}" data-idx="${absIdx}" class="peer sr-only" ${checked}>
                    <div class="rounded-lg border border-white/10 peer-checked:border-indigo-400 peer-checked:bg-indigo-500/15 hover:border-white/20 px-3 py-2 text-sm transition flex items-center gap-2">
                        <span class="w-5 h-5 rounded-full border border-current opacity-60 peer-checked:bg-indigo-400 peer-checked:opacity-100 grid place-items-center text-[10px] font-bold">${String.fromCharCode(65 + oi)}</span>
                        <span>${escapeHtml(opt)}</span>
                    </div>
                </label>`;
            }).join('');
            answerHtml = `<div class="grid grid-cols-1 sm:grid-cols-2 gap-2">${optsHtml}</div>`;
        } else {
            answerHtml = `<textarea
                class="w-full bg-slate-900/60 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder-slate-500 focus:border-indigo-400 focus:outline-none min-h-[80px]"
                placeholder="Scrivi qui la tua risposta…"
                data-idx="${absIdx}"
            >${escapeHtml(state.questions[absIdx].risposta_utente || '')}</textarea>`;
        }

        card.innerHTML = `
            <div class="flex items-center justify-between gap-2 mb-3">
                <span class="text-[11px] uppercase tracking-wider text-indigo-300 font-semibold">${escapeHtml(q.materia)}</span>
                <span class="text-[11px] px-2 py-0.5 rounded-full ${diffColor}">diff ${q.difficolta}</span>
            </div>
            <p class="font-medium text-slate-100 leading-relaxed mb-3">${absIdx + 1}. ${escapeHtml(q.domanda)}</p>
            ${answerHtml}
        `;
        cards.appendChild(card);
    });

    cards.querySelectorAll('textarea').forEach(t => {
        t.addEventListener('input', () => {
            const i = parseInt(t.dataset.idx, 10);
            state.questions[i].risposta_utente = t.value;
            saveState();
        });
    });
    cards.querySelectorAll('input[type="radio"]').forEach(r => {
        r.addEventListener('change', () => {
            const i = parseInt(r.dataset.idx, 10);
            state.questions[i].risposta_utente = r.value;
            saveState();
        });
    });

    const isLastPage = (state.currentPage + 1) >= totalPages;
    const nextBtn = document.getElementById('nextBtn');
    nextBtn.textContent = isLastPage ? 'Vedi risultati →' : 'Avanti →';
    nextBtn.addEventListener('click', () => onNextPage(isLastPage));
    document.getElementById('resetBtn').addEventListener('click', () => {
        if (confirm('Vuoi davvero annullare il quiz? Perderai i progressi.')) {
            clearState();
            renderSetup();
        }
    });
}

function difficultyColor(d) {
    if (d <= 25) return 'bg-emerald-500/20 text-emerald-300';
    if (d <= 50) return 'bg-amber-500/20 text-amber-300';
    if (d <= 75) return 'bg-orange-500/20 text-orange-300';
    return 'bg-rose-500/20 text-rose-300';
}

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// -------- Avanzamento pagina --------
async function onNextPage(isLastPage) {
    const pageStart = state.currentPage * QUESTIONS_PER_PAGE;
    const pageEnd = pageStart + QUESTIONS_PER_PAGE;
    const pageQs = state.questions.slice(pageStart, pageEnd);

    app.innerHTML = '';
    app.appendChild(document.getElementById('tpl-loading').content.cloneNode(true));

    let scores;
    try {
        scores = await gradeBatch(pageQs);
    } catch (e) {
        alert('Errore durante la valutazione. Riprova.');
        console.error(e);
        renderQuiz();
        return;
    }

    pageQs.forEach((q, i) => {
        q.score = scores[i].score;
        q.motivazione = scores[i].motivazione;
        q.risposta_corretta = scores[i].risposta_corretta || '';
        if (scores[i].partial) q.partial = true;
    });

    if (isLastPage) {
        state.phase = 'results';
        saveState();
        renderResults();
        return;
    }

    // Adattiva: aggiorna livello in base alla media della pagina
    if (state.cfg.mode === 'adattiva') {
        const avg = pageQs.reduce((s, q) => s + (q.score || 0), 0) / pageQs.length;
        const idx = LEVEL_ORDER.indexOf(state.currentLevel);
        let newIdx = idx;
        if (avg >= 70 && idx < LEVEL_ORDER.length - 1) newIdx = idx + 1;
        else if (avg < 40 && idx > 0) newIdx = idx - 1;
        state.currentLevel = LEVEL_ORDER[newIdx];
        const maxIdx = LEVEL_ORDER.indexOf(state.maxLevelReached || 'facile');
        if (newIdx > maxIdx) state.maxLevelReached = state.currentLevel;
    }

    // Pesca prossimo batch
    let nextBatch;
    try {
        nextBatch = await fetchBatch();
    } catch (e) {
        alert('Errore caricamento prossime domande. Riprova.');
        renderQuiz();
        return;
    }
    if (!nextBatch || nextBatch.length === 0) {
        state.phase = 'results';
        saveState();
        renderResults();
        return;
    }
    state.questions.push(...nextBatch);
    state.seenIds.push(...nextBatch.map(q => q._id));
    state.currentPage += 1;
    saveState();
    renderQuiz();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function gradeBatch(pageQs) {
    const items = pageQs.map(q => {
        const isMultiple = Array.isArray(q.opzioni) && q.opzioni.length >= 2;
        return isMultiple
            ? { _id: q._id, scelta_utente: q.risposta_utente || '' }
            : { _id: q._id, testo_utente: q.risposta_utente || '' };
    });
    const res = await fetch('api/grade-batch.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ answerType: state.cfg.answerType, items }),
    });
    if (!res.ok) throw new Error('grade-batch HTTP ' + res.status);
    const data = await res.json();
    if (!data || !Array.isArray(data.scores)) throw new Error('invalid response');
    return data.scores;
}

// -------- Risultati --------
function renderResults() {
    app.innerHTML = '';
    const tpl = document.getElementById('tpl-results').content.cloneNode(true);
    app.appendChild(tpl);

    const qs = state.questions.filter(q => typeof q.score === 'number');
    const totalWeight = qs.reduce((s, q) => s + q.difficolta, 0);
    const weighted = qs.reduce((s, q) => s + q.score * q.difficolta, 0);
    const finalScore = totalWeight > 0 ? Math.round(weighted / totalWeight) : 0;

    document.getElementById('finalName').textContent = state.cfg.nome || '';
    document.getElementById('finalScore').textContent = finalScore;
    document.getElementById('finalLabel').textContent = labelForScore(finalScore);
    document.getElementById('statsCount').textContent = qs.length;
    const avgDiff = qs.length ? Math.round(qs.reduce((s, q) => s + q.difficolta, 0) / qs.length) : 0;
    document.getElementById('statsAvgDiff').textContent = avgDiff;

    let levelLabel = 'Manuale';
    let levelKey = '';
    if (state.cfg.mode === 'adattiva') {
        levelKey = state.maxLevelReached || 'facile';
        levelLabel = { facile: 'Facile', medio: 'Medio', difficile: 'Difficile', esperto: 'Esperto' }[levelKey] || 'Facile';
    } else {
        if (avgDiff <= 25) levelKey = 'facile';
        else if (avgDiff <= 50) levelKey = 'medio';
        else if (avgDiff <= 75) levelKey = 'difficile';
        else levelKey = 'esperto';
        levelLabel = { facile: 'Facile', medio: 'Medio', difficile: 'Difficile', esperto: 'Esperto' }[levelKey];
    }
    document.getElementById('statsLevel').textContent = levelLabel;

    const showLeaderboard = (data) => {
        setupLeaderboardTabs('result', 'resultLeaderboardList', 'resultLeaderboardTotal', {
            defaultTab: state.cfg.mode,
            highlightName: state.cfg.nome,
            preloadedData: data,
        });
    };

    if (!state.saved) {
        state.saved = true;
        saveState();
        saveResult({
            nome: state.cfg.nome,
            score: finalScore,
            mode: state.cfg.mode,
            qcount: state.cfg.qcount,
            spec: state.cfg.spec,
            avgDifficulty: avgDiff,
            levelReached: levelKey,
            answerType: state.cfg.answerType,
        }).then(res => {
            if (res && res.ok) {
                showLeaderboard(res.leaderboard);
                const myEntry = (res.leaderboard && res.leaderboard[res.mode] && res.leaderboard[res.mode].top5 || [])
                    .find(e => e.nome === state.cfg.nome && e.score === finalScore);
                if (myEntry && myEntry.lbScore !== undefined) {
                    document.getElementById('lbScoreValue').textContent = myEntry.lbScore;
                    document.getElementById('lbScoreBlock').classList.remove('hidden');
                } else {
                    const bonus = state.cfg.answerType === 'open' ? 1.0 : 0.8;
                    const lbApprox = Math.round(Math.max(0, Math.min(100, finalScore * (0.5 + avgDiff/200 + state.cfg.spec/200) * bonus / 1.5)));
                    document.getElementById('lbScoreValue').textContent = lbApprox;
                    document.getElementById('lbScoreBlock').classList.remove('hidden');
                }
                if (res.position && res.position <= 5) {
                    const modeLabel = res.mode === 'adattiva' ? 'Adattiva' : 'Manuale';
                    const el = document.getElementById('finalRank');
                    el.textContent = `🎉 Sei in classifica ${modeLabel} al ${ordinalIt(res.position)} posto!`;
                    el.className = 'mt-3 text-sm font-semibold text-amber-300';
                    el.classList.remove('hidden');
                }
            } else {
                showLeaderboard(null);
            }
        }).catch(err => {
            console.error('save-result failed', err);
            showLeaderboard(null);
        });
    } else {
        showLeaderboard(null);
    }

    // Top/bottom materie
    const byMateria = {};
    qs.forEach(q => {
        if (!byMateria[q.materia]) byMateria[q.materia] = { sum: 0, count: 0 };
        byMateria[q.materia].sum += q.score;
        byMateria[q.materia].count += 1;
    });
    const stats = Object.entries(byMateria).map(([nome, v]) => ({ nome, avg: v.sum / v.count, count: v.count }));
    stats.sort((a, b) => b.avg - a.avg);
    const topUL = document.getElementById('topMaterie');
    const botUL = document.getElementById('bottomMaterie');
    const strong = stats.filter(s => s.avg >= 70).slice(0, 3);
    const weak = stats.filter(s => s.avg < 70).slice(-3).reverse();
    strong.forEach(s => topUL.appendChild(materiaRow(s, 'emerald')));
    weak.forEach(s => botUL.appendChild(materiaRow(s, 'rose')));
    if (strong.length === 0) {
        topUL.innerHTML = '<li class="text-slate-500">Nessun dato.</li>';
    }
    if (weak.length === 0) {
        botUL.innerHTML = '<li class="text-slate-500">Nessun dato.</li>';
    }

    const review = document.getElementById('reviewList');
    qs.forEach((q, i) => {
        const block = document.createElement('div');
        const color = q.score >= 70 ? 'emerald' : q.score >= 40 ? 'amber' : 'rose';
        block.className = 'rounded-lg border border-white/10 bg-slate-900/40 p-3';
        block.innerHTML = `
            <div class="flex items-center justify-between gap-2 mb-2">
                <span class="text-[11px] uppercase text-slate-400">${i + 1}. ${escapeHtml(q.materia)} · diff ${q.difficolta}</span>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-${color}-500/20 text-${color}-300">${q.score}/100</span>
            </div>
            <p class="text-sm font-medium mb-2">${escapeHtml(q.domanda)}</p>
            <div class="text-xs text-slate-400 space-y-1">
                <p><span class="text-slate-500">Tu:</span> ${escapeHtml(q.risposta_utente || '(vuota)')}</p>
                <p><span class="text-slate-500">Corretta:</span> ${escapeHtml(q.risposta_corretta || '')}</p>
                <p class="text-slate-500 italic">${escapeHtml(q.motivazione || '')}</p>
            </div>
        `;
        review.appendChild(block);
    });

    document.getElementById('restartBtn').addEventListener('click', () => {
        clearState();
        renderSetup();
    });
}

function materiaRow(s, color) {
    const li = document.createElement('li');
    li.className = 'flex items-center justify-between';
    const v = Math.round(s.avg);
    li.innerHTML = `<span class="truncate pr-2">${escapeHtml(s.nome)}</span><span class="text-${color}-300 font-semibold">${v}</span>`;
    return li;
}

function labelForScore(s) {
    if (s >= 90) return 'Eccellente. Cultura solida e profonda.';
    if (s >= 75) return 'Ottimo risultato.';
    if (s >= 60) return 'Buon risultato, c’è margine di miglioramento.';
    if (s >= 45) return 'Risultato medio, qualche lacuna evidente.';
    if (s >= 30) return 'Risultato scarso, molti argomenti da rivedere.';
    return 'Risultato insufficiente.';
}

// -------- Leaderboard --------
async function loadLeaderboard() {
    try {
        const res = await fetch('api/leaderboard.php', { cache: 'no-store' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return await res.json();
    } catch (err) {
        console.error('loadLeaderboard', err);
        return { adattiva: { top5: [], total: 0 }, manuale: { top5: [], total: 0 } };
    }
}

async function saveResult(payload) {
    try {
        const res = await fetch('api/save-result.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return await res.json();
    } catch (err) {
        console.error('saveResult', err);
        return null;
    }
}

function setupLeaderboardTabs(tabsScope, listId, totalId, opts) {
    opts = opts || {};
    const tabsRoot = document.querySelector(`[data-leaderboard-tabs="${tabsScope}"]`);
    if (!tabsRoot) return;
    const buttons = tabsRoot.querySelectorAll('.lb-tab');

    let dataPromise = opts.preloadedData
        ? Promise.resolve(opts.preloadedData)
        : loadLeaderboard();

    let cached = null;
    let activeTab = opts.defaultTab || 'adattiva';

    const apply = () => {
        buttons.forEach(b => {
            const active = b.dataset.tab === activeTab;
            b.classList.toggle('bg-indigo-500/30', active);
            b.classList.toggle('text-white', active);
            b.classList.toggle('text-slate-400', !active);
            b.classList.toggle('hover:text-slate-200', !active);
        });
        const section = cached && cached[activeTab] ? cached[activeTab] : { top5: [], total: 0 };
        renderLeaderboard(section, listId, totalId, { highlightName: opts.highlightName });
    };

    buttons.forEach(b => {
        b.addEventListener('click', () => {
            activeTab = b.dataset.tab;
            apply();
        });
    });

    dataPromise.then(d => { cached = d || { adattiva: { top5: [], total: 0 }, manuale: { top5: [], total: 0 } }; apply(); });
}

function renderLeaderboard(data, listId, totalId, opts) {
    opts = opts || {};
    const list = document.getElementById(listId);
    const total = document.getElementById(totalId);
    if (!list) return;
    list.innerHTML = '';
    const top5 = (data && data.top5) || [];
    if (total) total.textContent = data && typeof data.total === 'number' ? `${data.total} quiz totali in questa modalità` : '';

    if (top5.length === 0) {
        list.innerHTML = '<li class="text-slate-500 italic">Nessun risultato ancora. Sii il primo!</li>';
        return;
    }

    const medals = ['🥇', '🥈', '🥉'];
    const levelLabels = { facile: 'Facile', medio: 'Medio', difficile: 'Difficile', esperto: 'Esperto' };
    const levelColors = { facile: 'text-emerald-300', medio: 'text-amber-300', difficile: 'text-orange-300', esperto: 'text-rose-300' };
    const deriveLevelFromAvg = (avg) => {
        const a = avg || 0;
        if (a <= 25) return 'facile';
        if (a <= 50) return 'medio';
        if (a <= 75) return 'difficile';
        return 'esperto';
    };
    top5.forEach((entry, i) => {
        const li = document.createElement('li');
        const isMe = opts.highlightName && entry.nome === opts.highlightName;
        const baseClass = 'flex items-center justify-between gap-2 px-3 py-2 rounded-lg';
        const bg = isMe ? 'bg-indigo-500/15 border border-indigo-400/30' : 'bg-slate-900/40';
        li.className = `${baseClass} ${bg}`;
        const date = entry.timestamp ? new Date(entry.timestamp * 1000) : null;
        const dateStr = date ? date.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: '2-digit' }) : '';
        const levelKey = entry.mode === 'adattiva' ? (entry.level || deriveLevelFromAvg(entry.avgDiff)) : deriveLevelFromAvg(entry.avgDiff);
        const levelLabel = levelLabels[levelKey] || '—';
        const levelColor = levelColors[levelKey] || 'text-slate-400';
        const lbScore = (entry.lbScore !== undefined && entry.lbScore !== null) ? entry.lbScore : entry.score;
        const aType = entry.answerType || 'multiple';
        const aBadge = aType === 'open' ? '✏️' : '🔘';
        li.innerHTML = `
            <div class="flex items-center gap-3 min-w-0">
                <span class="text-base shrink-0 w-6 text-center">${medals[i] || (i + 1)}</span>
                <div class="min-w-0">
                    <p class="font-semibold truncate"><span class="mr-1">${aBadge}</span>${escapeHtml(entry.nome || '?')}</p>
                    <p class="text-[11px] text-slate-500">
                        ${dateStr} · ${entry.qcount || '?'} dom. · <span class="${levelColor}">${levelLabel}</span> · spec ${entry.spec || '-'} · score ${entry.score}
                    </p>
                </div>
            </div>
            <span class="text-lg font-bold ${i === 0 ? 'text-amber-300' : 'text-indigo-300'}" title="Punti classifica">${lbScore}</span>
        `;
        list.appendChild(li);
    });
}

function ordinalIt(n) {
    const map = { 1: 'primo', 2: 'secondo', 3: 'terzo', 4: 'quarto', 5: 'quinto' };
    return map[n] || `${n}°`;
}
