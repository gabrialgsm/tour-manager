let currentBus = BUSES[0]?.id || 0;
let seats = [];
let CHECKIN_STATUS = {};
let GMJS_SWAP_CSRF = null;
let gmjsDragPassengerId = 0;
let gmjsSeatDragJustEnded = false;

const ciStyle = document.createElement('style');
ciStyle.textContent =
  '.seat.checked-in{background:#dbeafe!important;border-color:#60a5fa!important;color:#1d4ed8!important}' +
  '.seat.checked-in small{color:#1d4ed8!important;font-weight:900}' +
  '.seat-load-error{padding:16px;border:1px solid #fecaca;background:#fff1f2;color:#b91c1c;border-radius:12px;font-weight:700}';
document.head.appendChild(ciStyle);

const money = n => '৳' + Number(n || 0).toLocaleString('en-BD', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
});
const esc = x => String(x ?? '').replace(/[&<>"']/g, m => ({
  '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
}[m]));
const room = x => ({
  AC_COUPLE:'AC Couple',
  NON_AC_COUPLE:'Non-AC Couple',
  AC_4_BED:'AC 4 Bed',
  NON_AC_4_BED:'Non-AC 4 Bed',
  AC_TWIN_BED:'AC Twin Bed'
}[x] || x);

async function loadCheckinStatus(){
  try{
    const ids = PASSENGERS.map(p => Number(p.id)).filter(Boolean);
    if(!ids.length){ CHECKIN_STATUS = {}; return; }

    const r = await fetch(
      'checkin_status.php?ids=' + encodeURIComponent(ids.join(',')),
      {credentials:'same-origin', headers:{'Accept':'application/json'}}
    );
    const d = await r.json();
    if(d && d.ok) CHECKIN_STATUS = d.checked || {};
  }catch(e){
    console.warn('Check-in status could not be loaded:', e);
  }
}

/*
 * API compatibility:
 * Older API returned a bare array.
 * Some newer versions return {ok:true,seats:[...]}.
 * This normalizer supports both so a harmless response-shape
 * change cannot break the dashboard with "seats.filter is not a function".
 */
function normalizeSeatResponse(data){
  if(Array.isArray(data)) return data;
  if(data && Array.isArray(data.seats)) return data.seats;
  if(data && Array.isArray(data.data)) return data.data;
  if(data && Array.isArray(data.rows)) return data.rows;
  return null;
}

async function loadSeats(){
  const seatMap = document.getElementById('seatMap');
  try{
    const r = await fetch(
      'api/seats.php?bus_id=' + encodeURIComponent(currentBus),
      {credentials:'same-origin', headers:{'Accept':'application/json'}}
    );

    const raw = await r.json();
    const normalized = normalizeSeatResponse(raw);

    if(!r.ok || !normalized){
      const msg = raw?.message || raw?.error || 'Seat data could not be loaded.';
      throw new Error(msg);
    }

    seats = normalized;
    await loadCheckinStatus();
    renderSeats();
  }catch(e){
    console.error('Seat API error:', e);
    seats = [];
    if(seatMap){
      seatMap.innerHTML =
        '<div class="seat-load-error">Unable to load seats for this bus. ' +
        esc(e.message || '') + '</div>';
    }
  }
}

function renderSeats(){
  const seatMap = document.getElementById('seatMap');
  if(!seatMap) return;

  const all = seats
    .filter(s => Number(s.bus_id) === Number(currentBus))
    .sort((a,b) =>
      Number(a.row_no)-Number(b.row_no) ||
      Number(a.col_no)-Number(b.col_no) ||
      String(a.seat_no).localeCompare(String(b.seat_no), undefined, {numeric:true})
    );

  if(!all.length){
    seatMap.innerHTML =
      '<div class="seat-load-error">No seats configured for this bus.</div>';
    return;
  }

  /*
   * Render rows explicitly instead of letting CSS grid auto-place them.
   * This prevents seats from collapsing into a long horizontal line.
   *
   * Supported existing data:
   *   - legacy 5-across: A1 A2 A3 A4 A5
   *   - 2+2:             A1 A2 | A3 A4
   *   - front single:    A0/S1 above A1, always on the left
   *   - last row with 5: A1 A2 | A3 A4 A5
   */
  const groups = new Map();
  all.forEach(s => {
    const key = Number(s.row_no);
    if(!groups.has(key)) groups.set(key, []);
    groups.get(key).push(s);
  });

  const rows = [...groups.entries()].sort((a,b) => a[0]-b[0]);
  let html = '<div class="driver">DRIVER</div>';

  function isFrontSingle(s, rowSeats){
    const n = String(s.seat_no || '').trim().toUpperCase();
    return /^(?:[A-Z])0$/.test(n) || /^S1$/.test(n);
  }

  function seatButton(s){
    const p = PASSENGERS.find(x => Number(x.seat_id) === Number(s.id));
    const due = p
      ? Number(p.final_fee) - Number(PAID_MAP[p.id] || 0)
      : 0;
    const checked = !!(p && CHECKIN_STATUS[String(p.id)]);

    const cls = 'seat ' + (
      p ? (checked ? 'checked-in' : (due > 0 ? 'due' : 'booked')) : ''
    );

    const label = checked
      ? '✓ CHECKED IN'
      : (p ? p.name : '');

    const dragAttrs = p
      ? `draggable="true" data-seat-id="${Number(s.id)}" data-passenger-id="${Number(p.id)}" data-bus-id="${Number(s.bus_id)}"`
      : `draggable="false" data-seat-id="${Number(s.id)}" data-bus-id="${Number(s.bus_id)}"`;

    return `<button type="button"
      class="${cls}"
      ${dragAttrs}
      title="${esc(checked
        ? 'Drag to another seat to move/swap • Click to open passenger'
        : (p ? 'Drag to another seat to move/swap • Click to open passenger' : 'Available — drop a passenger here'))}"
      onclick="${p
        ? `if(window.gmjsSeatDragJustEnded){window.gmjsSeatDragJustEnded=false;return;}location.href='passenger_form.php?id=${p.id}'`
        : ''}">
      <strong>${esc(s.seat_no)}</strong>
      ${label ? `<small>${esc(label)}</small>` : ''}
    </button>`;
  }

  rows.forEach(([, rawSeats], index) => {
    const rowSeats = rawSeats.slice();

    // A0/S1 is a dedicated front single seat. Keep it above A1 and left-aligned.
    const singles = rowSeats.filter(s => isFrontSingle(s, rowSeats));
    const normal = rowSeats.filter(s => !isFrontSingle(s, rowSeats));

    if(index === 0 && singles.length){
      html += '<div class="seat-row front-single-row">';
      html += `<div class="front-single-seat">${seatButton(singles[0])}</div>`;
      html += '</div>';
    }

    if(!normal.length) return;

    normal.sort((a,b) => Number(a.col_no)-Number(b.col_no));

    // Existing data may have 4 seats (2+2) or 5 seats (2+3).
    // If there are fewer than 4, keep them left-to-right without inventing seats.
    const left = normal.slice(0, Math.min(2, normal.length));
    const right = normal.slice(2);

    html += '<div class="seat-row normal-seat-row">';

    left.forEach(s => {
      html += seatButton(s);
    });

    html += '<div class="seat-row-aisle" aria-hidden="true"></div>';

    right.forEach(s => {
      html += seatButton(s);
    });

    html += '</div>';
  });

  seatMap.innerHTML = html;
  bindSeatDragDrop();
}
async function getSwapCsrf(){
  if(GMJS_SWAP_CSRF) return GMJS_SWAP_CSRF;
  const r = await fetch('seat_swap_ajax.php', {
    method:'GET',
    credentials:'same-origin',
    headers:{'Accept':'application/json'}
  });
  const d = await r.json();
  if(!r.ok || !d.ok || !d.csrf) throw new Error(d.message || 'Could not initialize seat swap.');
  GMJS_SWAP_CSRF = d.csrf;
  return GMJS_SWAP_CSRF;
}

function seatMapToast(message, isError=false){
  const toast = document.getElementById('toast');
  if(!toast) return;
  toast.textContent = message;
  toast.classList.add('show');
  if(isError) toast.style.background = '#991b1b';
  setTimeout(()=>{
    toast.classList.remove('show');
    toast.style.background = '';
  }, 2600);
}

async function performSeatSwap(sourceSeatId, targetSeatId){
  if(Number(sourceSeatId) === Number(targetSeatId)) return;

  const csrf = await getSwapCsrf();
  const fd = new FormData();
  fd.append('csrf', csrf);
  fd.append('source_seat_id', Number(sourceSeatId));
  fd.append('target_seat_id', Number(targetSeatId));

  const r = await fetch('seat_swap_ajax.php', {
    method:'POST',
    body:fd,
    credentials:'same-origin',
    headers:{
      'X-Requested-With':'XMLHttpRequest',
      'Accept':'application/json'
    }
  });

  let d = {};
  try { d = await r.json(); } catch(e) {}

  if(!r.ok || !d.ok) {
    throw new Error(d.message || 'Seat swap failed.');
  }

  // Keep the dashboard's in-memory passenger list synchronized.
  if(d.assignments){
    Object.entries(d.assignments).forEach(([pid, a])=>{
      const p = PASSENGERS.find(x => Number(x.id) === Number(pid));
      if(!p) return;
      p.bus_id = Number(a.bus_id);
      p.seat_id = Number(a.seat_id);
      p.seat_no = a.seat_no;
      p.bus_name = a.bus_name || p.bus_name;
    });
  }

  await loadSeats();
  renderTable();
  seatMapToast(d.message || 'Seat assignment updated.');
}

function bindSeatDragDrop(){
  const seatMap = document.getElementById('seatMap');
  if(!seatMap || seatMap.dataset.dragBound === '1') return;
  seatMap.dataset.dragBound = '1';

  seatMap.addEventListener('dragstart', async e=>{
    const seat = e.target.closest('.seat[data-seat-id]');
    if(!seat || !seat.dataset.passengerId) return;

    gmjsDragPassengerId = Number(seat.dataset.passengerId);
    seat.classList.add('dragging');

    try{
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', String(seat.dataset.seatId));
      await getSwapCsrf();
    }catch(err){
      e.preventDefault();
      gmjsDragPassengerId = 0;
      seat.classList.remove('dragging');
      seatMapToast(err.message || 'Drag & drop is unavailable.', true);
    }
  });

  seatMap.addEventListener('dragover', e=>{
    const target = e.target.closest('.seat[data-seat-id]');
    if(!target || !gmjsDragPassengerId) return;

    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    target.classList.add('drag-over');
  });

  seatMap.addEventListener('dragleave', e=>{
    const target = e.target.closest('.seat[data-seat-id]');
    if(target) target.classList.remove('drag-over');
  });

  seatMap.addEventListener('drop', async e=>{
    const target = e.target.closest('.seat[data-seat-id]');
    if(!target || !gmjsDragPassengerId) return;

    e.preventDefault();
    e.stopPropagation();

    const source = seatMap.querySelector(
      `.seat[data-passenger-id="${CSS.escape(String(gmjsDragPassengerId))}"]`
    );

    const sourceSeatId = source?.dataset.seatId || e.dataTransfer.getData('text/plain');
    const targetSeatId = target.dataset.seatId;

    seatMap.querySelectorAll('.drag-over,.dragging')
      .forEach(x=>x.classList.remove('drag-over','dragging'));

    try{
      await performSeatSwap(sourceSeatId, targetSeatId);
    }catch(err){
      seatMapToast(err.message || 'Seat swap failed.', true);
    }finally{
      gmjsSeatDragJustEnded = true;
      gmjsDragPassengerId = 0;
      setTimeout(()=>{gmjsSeatDragJustEnded=false;}, 500);
    }
  });

  seatMap.addEventListener('dragend', e=>{
    seatMap.querySelectorAll('.drag-over,.dragging')
      .forEach(x=>x.classList.remove('drag-over','dragging'));

    if(gmjsDragPassengerId){
      gmjsSeatDragJustEnded = true;
      setTimeout(()=>{gmjsSeatDragJustEnded=false;}, 500);
    }
    gmjsDragPassengerId = 0;
  });
}

function renderTable(){
  const search = document.getElementById('search');
  const q = (search?.value || '').toLowerCase();

  const a = PASSENGERS.filter(p =>
    [p.name,p.phone,p.seat_no,p.bus_name]
      .join(' ')
      .toLowerCase()
      .includes(q)
  );

  document.getElementById('passengerRows').innerHTML =
    a.map(p => {
      const due = Math.max(
        0,
        Number(p.final_fee) - Number(PAID_MAP[p.id] || 0)
      );

      return `<tr>
        <td>${esc(p.seat_no)}</td>
        <td><b>${esc(p.name)}</b></td>
        <td>${esc(p.phone)}</td>
        <td>${esc(room(p.room_type))}</td>
        <td>${money(p.final_fee)}</td>
        <td>${money(PAID_MAP[p.id] || 0)}</td>
        <td>${due
          ? '<span class="badge due">'+money(due)+'</span>'
          : '<span class="badge">Paid</span>'}</td>
        <td><a class="btn secondary" href="passenger_form.php?id=${p.id}">Edit</a></td>
      </tr>`;
    })
    .join('') || '<tr><td colspan="8">No passengers.</td></tr>';
}

document.querySelectorAll('.bus-tab').forEach(b => {
  b.onclick = () => {
    document.querySelectorAll('.bus-tab')
      .forEach(x => x.classList.remove('active'));

    b.classList.add('active');
    currentBus = Number(b.dataset.bus);
    loadSeats();
  };
});

document.getElementById('search')?.addEventListener('input', renderTable);

(async()=>{
  await loadCheckinStatus();
  await loadSeats();
  renderTable();
})();
