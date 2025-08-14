<?php // ui/products-section.php (relative REST + credentials) ?>
<div class="saas-products">
  <div class="filters">
    <select id="status-filter">
      <option value="all">Tümü</option>
      <option value="active">Aktif</option>
      <option value="inactive">Pasif</option>
    </select>
    <select id="category-filter"><option value="">Tüm Kategoriler</option></select>
    <select id="brand-filter"><option value="">Tüm Markalar</option></select>
    <input type="search" id="q" placeholder="Ara (ad/model/marka/kategori)" />
    <button id="fetch-products" class="btn">Ürünleri Getir</button>
  </div>

  <table class="wp-list-table widefat fixed striped trendyol-products-table">
    <thead>
      <tr>
        <th class="check-column"><input type="checkbox" id="select-all" /></th>
        <th>Görsel</th>
        <th>Ürün Adı</th>
        <th>Model</th>
        <th>Fiyat</th>
        <th>Kategori</th>
        <th>Marka</th>
        <th>Stok</th>
        <th>Renkler</th>
        <th>Açıklama</th>
        <th>İşlem</th>
      </tr>
    </thead>
    <tbody id="product-list"></tbody>
  </table>
</div>
<script>
(async function(){
  const $ = jQuery;
  const API = '/wp-json/saas/v1/products';
  const NONCE = "<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>";
  function esc(s){ return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  async function load(page=1){
    $('#product-list').html('<tr><td colspan="11">Yükleniyor...</td></tr>');
    try{
      const params = new URLSearchParams({
        service: 'trendyol',
        page: page,
        size: 25,
        status: $('#status-filter').val() === 'all' ? '' : $('#status-filter').val(),
        category: $('#category-filter').val(),
        brand: $('#brand-filter').val(),
        q: $('#q').val() || ''
      });
      const r = await fetch(API + '?' + params.toString(), { headers: { 'X-WP-Nonce': NONCE }, credentials: 'include' });
      if (!r.ok){ const msg = await r.text(); throw new Error('REST hata: '+r.status+' '+msg); }
      const j = await r.json();
      render(j);
    }catch(err){
      console.error(err);
      $('#product-list').html('<tr><td colspan="11" style="color:#b00;">Hata: '+esc(err.message)+'</td></tr>');
    }
  }
  function render(data){
    const products = data.products || [];
    const cats = data.categories || [];
    const brands = data.brands || [];
    $('#category-filter').html('<option value="">Tüm Kategoriler</option>' + cats.map(c=>`<option value="${esc(c)}">${esc(c)}</option>`).join(''));
    $('#brand-filter').html('<option value="">Tüm Markalar</option>' + brands.map(b=>`<option value="${esc(b)}">${esc(b)}</option>`).join(''));
    if (!products.length){
      $('#product-list').html('<tr><td colspan="11" class="trendyol-empty">Ürün yok.</td></tr>');
      return;
    }
    $('#product-list').html(products.map(p=>{
      const img = p.images && p.images[0] ? `<img src="${esc(p.images[0])}" style="width:46px;height:46px;object-fit:cover;border-radius:6px;"/>` : '';
      const price = (p.sale_price && p.sale_price !== p.price) ? `${p.sale_price} <small style="color:#666;text-decoration:line-through;">${p.price}</small>` : (p.price||'');
      const colors = (p.colors||[]).join(', ');
      const desc = esc((p.short_description||p.description||'').toString().slice(0,120));
      return `<tr>
        <td><input type="checkbox" class="row-check"/></td>
        <td>${img}</td>
        <td>${esc(p.name)}</td>
        <td>${esc(p.model)}</td>
        <td>${esc(price)}</td>
        <td>${esc(p.category)}</td>
        <td>${esc(p.brand)}</td>
        <td>${esc(p.quantity)}</td>
        <td>${esc(colors)}</td>
        <td>${desc}</td>
        <td><button class="btn btn-secondary btn-sm" data-details='${esc(JSON.stringify(p))}'>Detay</button></td>
      </tr>`;
    }).join(''));
  }
  $('#fetch-products').on('click', ()=>load(1));
  $('#status-filter, #category-filter, #brand-filter').on('change', ()=>load(1));
  $('#q').on('keydown', (e)=>{ if(e.key==='Enter') load(1); });
  load(1);
})();
</script>
