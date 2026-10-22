/*
 * Label template designer (Settings > Label templates). The label is drawn at physical size in
 * millimetres and scaled to fit; elements are rendered by the server (Blade partial shared with the
 * print sheet), so this file only moves, resizes, selects and edits them. Positions snap to 0.5 mm
 * and overlapping elements are tinted red.
 */
const MM_TO_PX = 96 / 25.4;
const SNAP_MM = 0.5;
const SNAP_PRECISE_MM = 0.1;

const snap = (value, step = SNAP_MM) => Math.round(value / step) * step;
const clamp = (value, low, high) => Math.min(Math.max(value, low), Math.max(low, high));
const round1 = (value) => Math.round(value * 10) / 10;

function designer(root, wire) {
    const ref = (name) => root.querySelector(`[data-ref="${name}"]`);
    const label = ref('label');
    const stage = ref('stage');
    const frame = ref('frame');
    const handle = ref('handle');
    const overlays = ref('overlays');
    const status = ref('status');
    const props = ref('props');
    const noSelection = ref('noselection');

    let elements = JSON.parse(root.dataset.elements);
    let width = parseFloat(root.dataset.width);
    let height = parseFloat(root.dataset.height);
    let selectedId = null;
    let scale = 1;
    let renderTicket = 0;
    let renderTimer = null;

    const find = (id) => elements.find((element) => element.id === id) || null;
    const nodeOf = (id) => label.querySelector(`.lbl-el[data-el-id="${id}"]`);
    const pxPerMm = () => label.getBoundingClientRect().width / width;
    const uid = () => `e${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;

    function setStatus(text) {
        status.textContent = text;
    }

    function fit() {
        const available = Math.max(160, stage.parentElement.clientWidth - 8);

        scale = clamp(available / (width * MM_TO_PX), 0.3, 6);
        label.style.width = `${width}mm`;
        label.style.height = `${height}mm`;
        label.style.transform = `scale(${scale})`;
        stage.style.width = `${width * MM_TO_PX * scale}px`;
        stage.style.height = `${height * MM_TO_PX * scale}px`;
        handle.style.transform = `scale(${1 / scale})`;
        handle.style.transformOrigin = 'center';
        ref('scale').textContent = `${width} × ${height} mm · ${Math.round(scale * 100)}%`;
        drawFrame();
    }

    function applyGeometry(element) {
        const node = nodeOf(element.id);

        if (node) {
            node.style.left = `${element.x}mm`;
            node.style.top = `${element.y}mm`;
            node.style.width = `${element.w}mm`;
            node.style.height = `${element.h}mm`;
        }

        drawFrame();
        drawOverlaps();
    }

    function drawFrame() {
        const element = find(selectedId);

        frame.classList.toggle('hidden', !element);

        if (!element) {
            return;
        }

        frame.style.left = `${element.x}mm`;
        frame.style.top = `${element.y}mm`;
        frame.style.width = `${element.w}mm`;
        frame.style.height = `${element.h}mm`;
    }

    function drawOverlaps() {
        overlays.replaceChildren();

        const boxes = elements.filter((element) => ['text', 'price', 'barcode', 'image'].includes(element.type));

        for (let a = 0; a < boxes.length; a++) {
            for (let b = a + 1; b < boxes.length; b++) {
                const x1 = Math.max(boxes[a].x, boxes[b].x);
                const y1 = Math.max(boxes[a].y, boxes[b].y);
                const x2 = Math.min(boxes[a].x + boxes[a].w, boxes[b].x + boxes[b].w);
                const y2 = Math.min(boxes[a].y + boxes[a].h, boxes[b].y + boxes[b].h);

                if (x1 < x2 && y1 < y2) {
                    const hit = document.createElement('div');

                    hit.style.cssText = `position:absolute;left:${x1}mm;top:${y1}mm;width:${x2 - x1}mm;height:${y2 - y1}mm;background:rgba(220,53,69,.28);border:1px solid rgba(220,53,69,.7);`;
                    overlays.appendChild(hit);
                }
            }
        }
    }

    function showProps() {
        const element = find(selectedId);

        props.classList.toggle('hidden', !element);
        noSelection.classList.toggle('hidden', !!element);

        if (!element) {
            return;
        }

        root.querySelectorAll('[data-show]').forEach((group) => {
            const typeOk = group.dataset.show.split(' ').includes(element.type);
            const fieldOk = !group.dataset.whenField || element.field === group.dataset.whenField;

            group.classList.toggle('hidden', !(typeOk && fieldOk));
        });

        root.querySelectorAll('[data-prop]').forEach((input) => {
            const value = element[input.dataset.prop];

            if (input.type === 'checkbox') {
                input.checked = !!value;
            } else {
                input.value = value ?? '';
            }
        });
    }

    function select(id) {
        selectedId = id;
        showProps();
        drawFrame();
    }

    function replaceNode(id, html) {
        const old = nodeOf(id);
        const holder = document.createElement('template');

        holder.innerHTML = html.trim();

        if (old) {
            old.replaceWith(holder.content.firstElementChild);
        } else {
            overlays.before(holder.content.firstElementChild);
        }
    }

    /** Ask the server for the element's markup and its sanitised values. */
    function rerender(element, then) {
        const ticket = ++renderTicket;

        wire.renderElement(element, width, height).then((result) => {
            if (!result || ticket !== renderTicket && !then) {
                return;
            }

            const index = elements.findIndex((item) => item.id === element.id);

            if (index === -1) {
                return;
            }

            elements[index] = { ...result.element, id: element.id };
            replaceNode(element.id, result.html);
            applyGeometry(elements[index]);

            if (selectedId === element.id) {
                showProps();
            }

            if (then) {
                then(elements[index]);
            }
        });
    }

    function addElement(definition, x, y) {
        const element = { ...definition, id: uid() };

        element.x = snap(clamp(x, 0, width - element.w));
        element.y = snap(clamp(y, 0, height - element.h));
        elements.push(element);
        rerender(element, (saved) => select(saved.id));
    }

    function removeSelected() {
        const element = find(selectedId);

        if (!element) {
            return;
        }

        nodeOf(element.id)?.remove();
        elements = elements.filter((item) => item.id !== element.id);
        select(null);
        drawOverlaps();
    }

    function duplicateSelected() {
        const element = find(selectedId);

        if (element) {
            addElement({ ...element }, element.x + 2, element.y + 2);
        }
    }

    // Palette: drag onto the label, or click to add at the top-left corner.
    root.querySelectorAll('[data-new]').forEach((button) => {
        button.addEventListener('dragstart', (event) => {
            event.dataTransfer.setData('text/plain', button.dataset.new);
            event.dataTransfer.effectAllowed = 'copy';
        });
        button.addEventListener('click', () => addElement(JSON.parse(button.dataset.new), 1, 1));
    });

    stage.addEventListener('dragover', (event) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    });
    stage.addEventListener('drop', (event) => {
        event.preventDefault();

        let definition;

        try {
            definition = JSON.parse(event.dataTransfer.getData('text/plain'));
        } catch {
            return;
        }

        const rect = label.getBoundingClientRect();
        const ppm = rect.width / width;

        addElement(definition, (event.clientX - rect.left) / ppm - (definition.w || 0) / 2, (event.clientY - rect.top) / ppm - (definition.h || 0) / 2);
    });

    // Move.
    label.addEventListener('pointerdown', (event) => {
        const node = event.target.closest('.lbl-el');

        if (!node || event.target === handle) {
            if (event.target === label || event.target === overlays) {
                select(null);
            }

            return;
        }

        const element = find(node.dataset.elId);

        if (!element) {
            return;
        }

        event.preventDefault();
        select(element.id);
        stage.focus({ preventScroll: true });

        const ppm = pxPerMm();
        const origin = { x: element.x, y: element.y, px: event.clientX, py: event.clientY };
        const move = (e) => {
            element.x = snap(clamp(origin.x + (e.clientX - origin.px) / ppm, 0, width - element.w));
            element.y = snap(clamp(origin.y + (e.clientY - origin.py) / ppm, 0, height - element.h));
            applyGeometry(element);
            syncGeometryInputs(element);
        };
        const stop = () => {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', stop);
        };

        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', stop);
    });

    // Resize from the bottom-right handle.
    handle.addEventListener('pointerdown', (event) => {
        const element = find(selectedId);

        if (!element) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const ppm = pxPerMm();
        const origin = { w: element.w, h: element.h, px: event.clientX, py: event.clientY };
        const thin = element.type === 'line';
        const move = (e) => {
            element.w = clamp(snap(origin.w + (e.clientX - origin.px) / ppm), 1, width - element.x);
            element.h = clamp(snap(origin.h + (e.clientY - origin.py) / ppm, thin ? SNAP_PRECISE_MM : SNAP_MM), thin ? 0.1 : 1, height - element.y);
            applyGeometry(element);
            syncGeometryInputs(element);
        };
        const stop = () => {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', stop);
        };

        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', stop);
    });

    function syncGeometryInputs(element) {
        ['x', 'y', 'w', 'h'].forEach((key) => {
            const input = root.querySelector(`[data-prop="${key}"]`);

            if (input) {
                input.value = round1(element[key]);
            }
        });
    }

    // Property panel.
    function onProp(event) {
        const input = event.target.closest('[data-prop]');
        const element = find(selectedId);

        if (!input || !element) {
            return;
        }

        const key = input.dataset.prop;
        let value;

        if (input.type === 'checkbox') {
            value = input.checked;
        } else if (input.type === 'number') {
            value = parseFloat(input.value);

            if (Number.isNaN(value)) {
                return;
            }
        } else {
            value = input.value;
        }

        element[key] = value;

        if (['x', 'y', 'w', 'h'].includes(key)) {
            element.w = clamp(element.w, 0.1, width);
            element.h = clamp(element.h, 0.1, height);
            element.x = clamp(element.x, 0, width - element.w);
            element.y = clamp(element.y, 0, height - element.h);
            applyGeometry(element);

            return;
        }

        if (key === 'field') {
            showProps();
        }

        clearTimeout(renderTimer);
        renderTimer = setTimeout(() => rerender(element), event.type === 'input' ? 250 : 0);
    }

    props.addEventListener('input', onProp);
    props.addEventListener('change', onProp);
    ref('remove').addEventListener('click', removeSelected);
    ref('duplicate').addEventListener('click', duplicateSelected);

    // Keyboard: arrows nudge by 0.5 mm, Delete removes.
    stage.addEventListener('keydown', (event) => {
        const element = find(selectedId);

        if (!element) {
            return;
        }

        const steps = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };

        if (steps[event.key]) {
            event.preventDefault();
            element.x = clamp(element.x + steps[event.key][0] * SNAP_MM, 0, width - element.w);
            element.y = clamp(element.y + steps[event.key][1] * SNAP_MM, 0, height - element.h);
            applyGeometry(element);
            syncGeometryInputs(element);
        } else if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            removeSelected();
        }
    });

    // Label size.
    function onSize() {
        const newWidth = parseFloat(ref('width').value);
        const newHeight = parseFloat(ref('height').value);

        if (newWidth >= 15 && newWidth <= 300 && newHeight >= 10 && newHeight <= 300) {
            width = newWidth;
            height = newHeight;
            elements.forEach((element) => {
                element.w = Math.min(element.w, width);
                element.h = Math.min(element.h, height);
                element.x = clamp(element.x, 0, width - element.w);
                element.y = clamp(element.y, 0, height - element.h);
                applyGeometry(element);
            });
            fit();
        }
    }

    ref('width').addEventListener('input', onSize);
    ref('height').addEventListener('input', onSize);
    window.addEventListener('resize', fit);

    // Save.
    wire.$on('label-designer-saved', ({ ok }) => {
        ref('save').disabled = false;
        setStatus(ok ? root.dataset.msgSaved : root.dataset.msgInvalid);
    });
    ref('save').addEventListener('click', () => {
        ref('save').disabled = true;
        setStatus('…');
        wire.save(ref('name').value, width, height, elements);
    });

    fit();
    drawOverlaps();
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('labelDesigner', () => ({
        init() {
            designer(this.$el, this.$wire);
        },
    }));
});
