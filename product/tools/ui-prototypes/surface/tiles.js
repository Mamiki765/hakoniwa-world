// 仮タイル。本番の地形・施設画像はリポジトリ外にあるため、試作では16×16のドット絵をcanvasで描いて代用する。
// 実装時は MapCell.asset.url の画像に置き換わる。
(function () {
    const C = {
        sea: '#1d4f91', sea2: '#2a63ad', shallow: '#3f8fc4', shallow2: '#66add6',
        grass: '#5a9e3d', grass2: '#4a8a33', grass3: '#79b857', sand: '#b9a36a', sand2: '#a08a55',
        rock: '#8a7458', rock2: '#6b5942', snow: '#eef2f0', tree: '#2f6b2a', tree2: '#235420', trunk: '#5b3d22',
        ash: '#4a4340', ash2: '#352f2d', roof: '#c8553d', roof2: '#9d3f2c', wall: '#e9e2cf', wall2: '#bfb69c',
        steel: '#8f9aa5', steel2: '#5f6b77', dark: '#26303a', gold: '#e2b23a', white: '#ffffff', red: '#d03a3a',
        field: '#d6c25a', field2: '#a8963b', soil: '#7a5a34', wood: '#8b5e34', glass: '#9fd3e6',
    };
    const base = {
        sea(p, s) { p(C.sea, 0, 0, 16, 16); [[2, 3], [9, 6], [5, 11], [12, 13]].forEach(([x, y], i) => p(C.sea2, (x + s * 3 + i) % 14, y, 3, 1)); },
        shallow(p, s) { p(C.shallow, 0, 0, 16, 16); [[1, 4], [8, 2], [4, 9], [11, 12]].forEach(([x, y], i) => p(C.shallow2, (x + s * 5 + i) % 13, y, 4, 1)); },
        plain(p, s) { p(C.grass, 0, 0, 16, 16); for (let i = 0; i < 6; i++) p(i % 2 ? C.grass2 : C.grass3, (s * 7 + i * 5) % 15, (s * 3 + i * 7) % 15, 1, 1); },
        wasteland(p, s) { p(C.sand, 0, 0, 16, 16); for (let i = 0; i < 7; i++) p(C.sand2, (s * 5 + i * 5) % 14, (s + i * 7) % 15, 2, 1); },
        scorched(p, s) { p(C.ash, 0, 0, 16, 16); for (let i = 0; i < 7; i++) p(C.ash2, (s * 3 + i * 5) % 14, (s + i * 7) % 15, 2, 1); p(C.red, 7, 8, 1, 1); },
        forest(p, s) {
            base.plain(p, s);
            [[1, 2], [9, 1], [5, 7], [11, 9], [1, 10]].forEach(([x, y]) => { p(C.tree2, x + 1, y, 2, 1); p(C.tree, x, y + 1, 4, 2); p(C.tree2, x, y + 3, 4, 1); p(C.trunk, x + 1, y + 4, 2, 1); });
        },
        mountain(p, s) {
            base.plain(p, s);
            for (let i = 0; i < 9; i++) p(i % 3 ? C.rock : C.rock2, 7 - i * 0.8, 3 + i, 2 + i * 1.6, 1);
            p(C.rock2, 0, 12, 16, 2); p(C.snow, 6, 3, 3, 1); p(C.snow, 5, 4, 5, 1); p(C.snow, 7, 2, 1, 1);
        },
    };
    const house = (p, x, y, w, h, roof = C.roof) => { p(roof, x, y, w, 2); p(C.wall, x, y + 2, w, h - 2); p(C.dark, x + 1, y + h - 2, 1, 2); };
    const tower = (p, x, y, w, h, col = C.steel) => { p(col, x, y, w, h); for (let j = y + 1; j < y + h - 1; j += 2) for (let i = x + 1; i < x + w - 1; i += 2) p(C.glass, i, j, 1, 1); };
    const over = {
        village(p) { house(p, 2, 8, 5, 5); house(p, 9, 5, 5, 5, C.roof2); },
        town(p) { house(p, 1, 9, 4, 5); house(p, 6, 6, 5, 6, C.roof2); house(p, 11, 9, 4, 5); p(C.wall2, 0, 14, 16, 1); },
        city(p) { tower(p, 1, 5, 4, 10); tower(p, 6, 2, 5, 13, C.steel2); tower(p, 12, 7, 3, 8); p(C.dark, 0, 15, 16, 1); },
        capital(p) { tower(p, 2, 6, 3, 9, C.wall2); tower(p, 11, 6, 3, 9, C.wall2); tower(p, 6, 2, 4, 13, C.wall); p(C.gold, 7, 0, 2, 2); p(C.red, 9, 0, 2, 1); p(C.dark, 0, 15, 16, 1); },
        farm(p) { p(C.soil, 1, 1, 14, 14); for (let y = 2; y < 14; y += 2) p(y % 4 ? C.field : C.field2, 2, y, 12, 1); },
        factory(p) { p(C.steel2, 1, 8, 14, 7); p(C.steel, 1, 6, 4, 2); p(C.steel, 6, 6, 4, 2); p(C.steel, 11, 6, 4, 2); p(C.dark, 11, 1, 2, 5); p(C.white, 9, 0, 3, 1); p(C.gold, 3, 11, 2, 2); p(C.gold, 7, 11, 2, 2); },
        mine(p) { p(C.dark, 5, 9, 6, 6); p(C.wood, 4, 8, 8, 1); p(C.wood, 4, 8, 1, 7); p(C.wood, 11, 8, 1, 7); p(C.gold, 7, 12, 2, 1); },
        missile_base(p) { p(C.steel2, 2, 12, 12, 3); p(C.white, 7, 3, 2, 9); p(C.red, 7, 1, 2, 2); p(C.steel, 5, 10, 2, 2); p(C.steel, 9, 10, 2, 2); },
        defense(p) { p(C.steel2, 2, 10, 12, 5); p(C.steel, 4, 6, 8, 4); p(C.glass, 6, 3, 4, 3); p(C.red, 7, 1, 2, 2); },
        port(p) { p(C.wood, 0, 7, 11, 2); p(C.wood, 4, 2, 2, 12); p(C.wall, 11, 5, 4, 6); p(C.roof, 11, 4, 4, 1); p(C.trunk, 1, 9, 1, 3); p(C.trunk, 8, 9, 1, 3); },
        monument(p) { p(C.steel2, 4, 13, 8, 2); p(C.dark, 6, 2, 4, 11); p(C.glass, 7, 3, 1, 9); },
        wind_power(p) { p(C.white, 7, 6, 2, 9); p(C.steel, 3, 5, 10, 1); p(C.steel, 7, 1, 2, 9); p(C.red, 7, 5, 2, 1); p(C.steel2, 5, 14, 6, 1); },
        seabed_oil_field(p) { p(C.dark, 3, 10, 10, 4); p(C.steel, 4, 3, 1, 7); p(C.steel, 11, 3, 1, 7); p(C.steel, 4, 3, 8, 1); p(C.steel, 7, 3, 2, 7); p(C.gold, 7, 1, 2, 2); },
    };
    const extra = {
        monster(p) { p('#6c3fa0', 4, 5, 8, 8); p('#8a5bc4', 5, 3, 6, 3); p(C.white, 6, 5, 2, 2); p(C.white, 9, 5, 2, 2); p(C.dark, 7, 6, 1, 1); p(C.dark, 10, 6, 1, 1); p(C.red, 6, 9, 5, 1); p('#6c3fa0', 3, 13, 3, 2); p('#6c3fa0', 10, 13, 3, 2); },
        ship(p) { p(C.wood, 2, 10, 12, 3); p(C.trunk, 3, 13, 10, 1); p(C.white, 8, 2, 5, 7); p(C.steel2, 7, 1, 1, 9); p(C.red, 8, 1, 3, 1); },
    };

    const cache = new Map();
    function draw(fn, seed) {
        const cv = document.createElement('canvas'); cv.width = cv.height = 32;
        const g = cv.getContext('2d');
        const p = (col, x, y, w, h) => { g.fillStyle = col; g.fillRect(Math.round(x * 2), Math.round(y * 2), Math.round(w * 2), Math.round(h * 2)); };
        fn(p, seed);
        return cv.toDataURL();
    }
    function tile(cell) {
        const seed = Math.abs(cell.x * 3 + cell.y * 5) % 4;
        const key = `${cell.terrain}:${cell.facility ?? ''}:${seed}`;
        if (!cache.has(key)) {
            cache.set(key, draw((p, s) => { (base[cell.terrain] ?? base.plain)(p, s); if (cell.facility && over[cell.facility]) over[cell.facility](p, s); }, seed));
        }
        return cache.get(key);
    }
    function sprite(name) {
        if (!cache.has(name)) cache.set(name, draw(extra[name], 0));
        return cache.get(name);
    }
    window.PROTO_TILES = { tile, sprite };
})();
