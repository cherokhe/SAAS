<?php // ui/orders-section.php (relative REST + credentials) ?>
<div class="saas-orders">
  <div class="filters">
    <input type="date" id="start"> <input type="date" id="end">
    <select id="status">
      <option value="">Tümü</option>
      <option value="pending">Bekliyor</option>
      <option value="shipped">Gönderildi</option>
      <option value="delivered">Teslim</option>
      <option value="returned">İade</option>
    </select>
    <button id="refresh" class="btn">Yenile</button>
  </div>
  <table class="orders-table">
    <thead>
      <tr>
        <th><input type="checkbox" id="select-all"></th>
        <th>#</th>
        <th>Sipariş No</th>
        <th>Müşteri Adı</th>
        <th>Tarih</th>
        <th>Ürün</th>
        <th>Durum</th>
        <th>İşlem</th>
        <th>Yazdırma</th>
      </tr>
    </thead>
    <tbody id="orders-list"></tbody>
  </table>
</div>
<script>
(async function(){
  const $ = jQuery;
  const API = '/wp-json/saas/v1/orders';
  const NONCE = "<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>";
  function fmt(ts){ if(!ts) return '-'; const d = new Date(ts); return d.toLocaleString('tr-TR'); }
  function esc(s){ return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;'); }
  async function load(page=0){
    try{
      const s = $('#status').val();
      const start = $('#start').val() ? new Date($('#start').val()).getTime() : ''; 
      const end   = $('#end').val()   ? new Date($('#end').val()+'T23:59:59').getTime() : '';
      const params = new URLSearchParams({ service:'trendyol', page, size:50 });
      if (s) params.set('status', s);
      if (start) params.set('start', start);
      if (end) params.set('end', end);
      const r = await fetch(API + '?' + params.toString(), { headers: { 'X-WP-Nonce': NONCE }, credentials: 'include' });
      if (!r.ok){ const msg = await r.text(); throw new Error('REST hata: '+r.status+' '+msg); }
      const j = await r.json();
      render(j.orders || []);
    }catch(err){
      console.error(err);
      jQuery('#orders-list').html('<tr><td colspan="9" style="color:#b00;">Hata: '+esc(err.message)+'</td></tr>');
    }
  }
  function render(orders){
    if (!orders.length){
      $('#orders-list').html('<tr><td colspan="9" class="trendyol-empty">Sipariş bulunamadı.</td></tr>');
      return;
    }
    let idx = 1;
    $('#orders-list').html(orders.map(o=>{
      const items = (o.items||[]).map(i=>`${esc(i.productName)} <small>(${esc(i.color||'-')} | ${esc(i.size||'-')})</small> x ${i.quantity}`).join('<br/>');
      const badge = (st)=>{ const map = {pending:'status-pending', shipped:'status-shipped', delivered:'status-delivered', returned:'status-returned'}; const lbl = {pending:'BEKLİYOR', shipped:'GÖNDERİLDİ', delivered:'TESLİM', returned:'İADE'}; return `<span class="order-status ${map[st]||'status-pending'}">${lbl[st]||'BEKLİYOR'}</span>`; };
      return `<tr>
        <td><input type="checkbox" class="order-checkbox" data-id="${esc(o.id)}"></td>
        <td>${idx++}</td>
        <td><strong>${esc(o.orderNumber)}</strong></td>
        <td>${esc(o.customer)}</td>
        <td>${fmt(o.created)}</td>
        <td>${items}</td>
        <td>${badge(o.status)}</td>
        <td><button class="btn view-details" data-o='${esc(JSON.stringify(o))}'>Detay</button></td>
        <td><button class="btn print-one" data-id="${esc(o.id)}">Yazdır</button></td>
      </tr>`;
    }).join(''));
  }
  $('#refresh').on('click', ()=>load(0));
  load(0);
})();
</script>
