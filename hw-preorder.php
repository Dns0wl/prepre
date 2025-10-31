<?php
/**
 * Plugin Name: Hayu Widyas - Pre-Order Tickets (No extra plugin)
 * Description: CPT "Pre-Order" + ACF fields + Fluent Forms sync + /pre-order + child slug /pre-order/new-pre-order & /pre-order/my-pre-orders + My Requests + auto status + CSV + status trail + email + POS dashboard (/po-pos) + Regenerate Pages + fallback form otomatis bila Fluent Forms (ID=6) belum siap. Soft gate: publik boleh mengisi, wajib login saat submit, auto-restore draft. Safe redirect untuk social login. Hard gate: /pre-order menampilkan layar pemilih dua kartu besar (New Pre-Order & My Pre-Orders) dengan gradien #3C6E71 + tombol Back + modal detail accordion + transisi halus (fade). Search mobile proporsional + grid 2/4 kolom. Login inline menggunakan XS Social Login shortcode.
 * Author: HW Dev
 * Version: 2.2.0
 */
if (!function_exists('hwpo_ticket_label')) {
  function hwpo_ticket_label($post_id) {
    // 1) Coba ambil custom label dari meta (kalau kamu sudah simpan)
    $label = get_post_meta($post_id, 'ticket_label', true);
    if (!empty($label)) return esc_html($label);

    // 2) Fallback bikin format HWPO-YYMMDD<ID>
    $post_date = get_post_field('post_date', $post_id);
    $yy = mysql2date('y', $post_date);
    $mm = mysql2date('m', $post_date);
    $dd = mysql2date('d', $post_date);
    // padding ID biar konsisten (opsional)
    $idp = str_pad((string)$post_id, 5, '0', STR_PAD_LEFT);
    return 'HWPO-' . $yy . $mm . $dd . $idp;
  }
}



if (!defined('HW_PO_FLUENT_FORM_ID')) { define('HW_PO_FLUENT_FORM_ID', 6); }
if (!defined('HW_PO_INTERNAL_EMAILS')) { define('HW_PO_INTERNAL_EMAILS', 'ops@hayuwidyas.com, preorder@hayuwidyas.com'); }
if (!defined('HW_PO_NOTIFY_CUSTOMER')) { define('HW_PO_NOTIFY_CUSTOMER', false); }
if (!defined('HW_POS_MANAGER_ROLE')) { define('HW_POS_MANAGER_ROLE', 'yith_pos_manager'); }
if (!defined('HW_PO_REQUIRE_LOGIN_FOR_SUBMIT')) { define('HW_PO_REQUIRE_LOGIN_FOR_SUBMIT', true); }
if (!defined('HW_PO_ENABLE_HEADER_SEARCH_OVERRIDE')) { define('HW_PO_ENABLE_HEADER_SEARCH_OVERRIDE', false); }

/** ==== NEW: base & child slugs + URL helpers ==== */
if (!defined('HW_PO_BASE_SLUG'))  { define('HW_PO_BASE_SLUG',  'pre-order'); }
if (!defined('HW_PO_CHILD_NEW'))  { define('HW_PO_CHILD_NEW',  'new-pre-order'); }
if (!defined('HW_PO_CHILD_MY'))   { define('HW_PO_CHILD_MY',   'my-pre-orders'); }
function hw_po_base_url(){ return home_url('/'.HW_PO_BASE_SLUG.'/'); }
function hw_po_child_url($child){ $child = trim($child,'/'); return home_url('/'.HW_PO_BASE_SLUG.'/'.$child.'/'); }

/* ============================================================
 * HW Pre-Order – Woo Address Bridge (prefill + sync Woo)
 * Scope: HANYA /pre-order/new-pre-order/ dan Form ID = HW_PO_FLUENT_FORM_ID
 * Versi: 2.2.1 (PHP 7.x safe)
 * ============================================================ */

if (!defined('HW_PO_DEBUG_BRIDGE')) define('HW_PO_DEBUG_BRIDGE', false);
if (!function_exists('hwpo_dbg')) {
    function hwpo_dbg($m){ if(HW_PO_DEBUG_BRIDGE) error_log('[HW-PO BRIDGE] '.$m); }
}

if (!function_exists('hw_po_is_header_search_override_enabled')) {
    function hw_po_is_header_search_override_enabled(){
        return (bool) apply_filters('hw_po_enable_header_search_override', HW_PO_ENABLE_HEADER_SEARCH_OVERRIDE);
    }
}

/** Deteksi konteks halaman form pre-order (ketat ke child slug) */
if (!function_exists('hwpo_is_preorder_form_context')) {
    function hwpo_is_preorder_form_context() {
        // pola router plugin: pagename = HW_PO_BASE_SLUG + hwpo_view=form
        if (function_exists('is_page') && defined('HW_PO_BASE_SLUG') && is_page(HW_PO_BASE_SLUG)) {
            $v = get_query_var('hwpo_view');
            if ($v === 'form') return true;
        }
        // fallback: cek path langsung (jaga-jaga builder/redirect)
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ($uri && defined('HW_PO_BASE_SLUG') && defined('HW_PO_CHILD_NEW')
            && strpos($uri, '/'.HW_PO_BASE_SLUG.'/'.HW_PO_CHILD_NEW.'/') !== false) {
            return true;
        }
        // fallback: referer
        $ref = wp_get_referer();
        if ($ref && defined('HW_PO_BASE_SLUG') && defined('HW_PO_CHILD_NEW')
            && strpos($ref, '/'.HW_PO_BASE_SLUG.'/'.HW_PO_CHILD_NEW.'/') !== false) {
            return true;
        }
        return false;
    }
}

/** Prefill: dukung 2 skema
 * A) SKEMA DIREKOMENDASI (billing_*):
 *    billing_first_name, billing_last_name, billing_country(ISO2), billing_state, billing_phone, billing_address_1, billing_postcode
 * B) SKEMA LAMA (optional fallback):
 *    names (gabungan), phone, address_1 ("City, Country")
 */
add_filter('fluentform_rendering_field_data', function($field, $form){
    if (!hwpo_is_preorder_form_context()) return $field;
    if (intval(isset($form->id)?$form->id:0) !== intval(HW_PO_FLUENT_FORM_ID)) return $field;
    if (!is_user_logged_in()) return $field;

    $name = isset($field['attributes']['name']) ? $field['attributes']['name'] : '';
    if (!$name) return $field;

    // jangan timpa kalau sudah ada nilai (draft/restore)
    $has = isset($field['value']) ? $field['value'] : (isset($field['attributes']['value']) ? $field['attributes']['value'] : '');
    if ($has !== '' && $has !== null) return $field;

    $uid = get_current_user_id();

    // SKEMA DIREKOMENDASI
    $billingKeys = array(
        'billing_first_name','billing_last_name','billing_country',
        'billing_state','billing_phone','billing_address_1','billing_postcode'
    );
    if (in_array($name, $billingKeys, true)) {
        $v = get_user_meta($uid, $name, true);

        // fallback country/state dari shipping jika billing kosong
        if (($v === '' || $v === null) && $name === 'billing_country') {
            $v = get_user_meta($uid, 'shipping_country', true);
        }
        if (($v === '' || $v === null) && $name === 'billing_state') {
            $v = get_user_meta($uid, 'shipping_state', true);
        }

        if ($v !== '' && $v !== null) {
            $field['value'] = $v;
            $field['attributes']['value'] = $v;
            hwpo_dbg('prefill '.$name.'='.$v);
        }
        return $field;
    }

    // SKEMA LAMA (opsional): names / phone / address_1
    if ($name === 'names') {
        $bf = get_user_meta($uid, 'billing_first_name', true);
        $bl = get_user_meta($uid, 'billing_last_name', true);
        $full = trim($bf.' '.$bl);
        if ($full !== '') {
            $field['value'] = $full;
            $field['attributes']['value'] = $full;
            hwpo_dbg('prefill names='.$full);
        }
        return $field;
    }
    if ($name === 'phone') {
        $bp = get_user_meta($uid, 'billing_phone', true);
        if ($bp !== '') {
            $field['value'] = $bp;
            $field['attributes']['value'] = $bp;
            hwpo_dbg('prefill phone='.$bp);
        }
        return $field;
    }
    if ($name === 'address_1') {
        $bc  = get_user_meta($uid, 'billing_city', true);
        $bco = get_user_meta($uid, 'billing_country', true);
        if ($bc || $bco) {
            $field['value'] = trim($bc.($bco? ', '.$bco : ''));
            $field['attributes']['value'] = $field['value'];
            hwpo_dbg('prefill address_1='.$field['value']);
        }
        return $field;
    }

    return $field;
}, 10, 2);

/** Helper parser untuk skema lama */
if (!function_exists('hwpo_parse_full_name_to_parts')) {
    function hwpo_parse_full_name_to_parts($names) {
        $names = trim(preg_replace('/\s+/', ' ', (string)$names));
        if ($names==='') return array('','');
        $parts = explode(' ', $names);
        $first = array_shift($parts);
        $last  = trim(implode(' ', $parts));
        return array($first,$last);
    }
}
if (!function_exists('hwpo_split_city_country')) {
    function hwpo_split_city_country($val) {
        $val = trim((string)$val);
        if ($val==='') return array('','');
        $norm = preg_replace('/\s*[,|-]\s*/', ',', $val);
        $parts = array_map('trim', explode(',', $norm));
        return array(isset($parts[0])?$parts[0]:'', isset($parts[1])?$parts[1]:'');
    }
}

/** Konversi nama negara → ISO2 jika perlu */
if (!function_exists('hwpo_to_iso2')) {
    function hwpo_to_iso2($country) {
        $country = trim((string)$country);
        if ($country==='') return '';
        if (preg_match('/^[A-Z]{2}$/i', $country)) return strtoupper($country);
        if (function_exists('WC') && WC()->countries) {
            foreach (WC()->countries->get_countries() as $code=>$label) {
                if (strcasecmp($label,$country)===0) return strtoupper($code);
            }
        }
        // fallback sederhana
        $map = array(
            'indonesia'=>'ID','singapore'=>'SG','malaysia'=>'MY','thailand'=>'TH','vietnam'=>'VN',
            'philippines'=>'PH','japan'=>'JP','china'=>'CN','australia'=>'AU',
            'united states'=>'US','usa'=>'US','united kingdom'=>'GB','uk'=>'GB','netherlands'=>'NL'
        );
        $k = strtolower($country);
        return strtoupper(isset($map[$k]) ? $map[$k] : '');
    }
}

/** SUBMIT: simpan ke billing_* + mirror ke shipping_* (hanya halaman target) */
$__hwpo_bridge_submit = function($entryId,$formData,$form){
    if (!hwpo_is_preorder_form_context()) { hwpo_dbg('skip wrong page'); return; }
    if (intval(isset($form->id)?$form->id:0) !== intval(HW_PO_FLUENT_FORM_ID)) { hwpo_dbg('skip wrong form'); return; }
    if (!is_user_logged_in()) { hwpo_dbg('skip not logged'); return; }
    $uid = get_current_user_id();

    // --- SKEMA DIREKOMENDASI (billing_*) ---
    $grab = function($k) use ($formData){
        return isset($formData[$k]) ? trim((string)$formData[$k]) : '';
    };
    $bf = $grab('billing_first_name');
    $bl = $grab('billing_last_name');
    $bp = preg_replace('/\s+/', '', $grab('billing_phone'));
    $b1 = $grab('billing_address_1');
    $bz = $grab('billing_postcode');
    $bcountry = strtoupper($grab('billing_country')); // ISO2
    $bstate   = strtoupper($grab('billing_state'));

    // bangun array dan filter kosong (tanpa arrow function)
    $bset = array(
        'billing_first_name'=>$bf,
        'billing_last_name' =>$bl,
        'billing_phone'     =>$bp,
        'billing_address_1' =>$b1,
        'billing_postcode'  =>$bz,
        'billing_country'   =>$bcountry,
        'billing_state'     =>$bstate,
    );
    foreach ($bset as $k=>$v) {
        if ($v === '' || $v === null) unset($bset[$k]);
    }

    if (!empty($bset)) {
        foreach($bset as $k=>$v){
            update_user_meta($uid,$k,$v);
            hwpo_dbg('billing '.$k.'='.$v);
        }
        // Mirror minimal (nama, telp, negara, prov, alamat1, kode pos)
        $mirror = array(
            'shipping_first_name' => isset($bset['billing_first_name']) ? $bset['billing_first_name'] : '',
            'shipping_last_name'  => isset($bset['billing_last_name'])  ? $bset['billing_last_name']  : '',
            'shipping_phone'      => isset($bset['billing_phone'])      ? $bset['billing_phone']      : '',
            'shipping_address_1'  => isset($bset['billing_address_1'])  ? $bset['billing_address_1']  : '',
            'shipping_postcode'   => isset($bset['billing_postcode'])   ? $bset['billing_postcode']   : '',
            'shipping_country'    => isset($bset['billing_country'])    ? $bset['billing_country']    : '',
            'shipping_state'      => isset($bset['billing_state'])      ? $bset['billing_state']      : '',
        );
        foreach($mirror as $k=>$v){
            if($v!=='') update_user_meta($uid,$k,$v);
        }
    }

    // --- SKEMA LAMA (opsional; jika form masih pakai names/phone/address_1) ---
    if (isset($formData['names']) || isset($formData['phone']) || isset($formData['address_1'])) {
        $names = isset($formData['names']) ? (string)$formData['names'] : '';
        $phone = isset($formData['phone']) ? (string)$formData['phone'] : '';
        $addr  = isset($formData['address_1']) ? (string)$formData['address_1'] : '';

        if ($names!=='') {
            list($f,$l) = hwpo_parse_full_name_to_parts($names);
            if ($f!=='') { update_user_meta($uid,'billing_first_name',$f);  update_user_meta($uid,'shipping_first_name',$f); }
            if ($l!=='') { update_user_meta($uid,'billing_last_name',$l);   update_user_meta($uid,'shipping_last_name',$l); }
        }
        if ($phone!=='') {
            $p = preg_replace('/\s+/', '', $phone);
            update_user_meta($uid,'billing_phone',$p);
            update_user_meta($uid,'shipping_phone',$p);
        }
        if ($addr!=='') {
            list($city,$country) = hwpo_split_city_country($addr);
            $iso = hwpo_to_iso2($country);
            if ($city!=='') {
                update_user_meta($uid,'billing_city',$city);
                update_user_meta($uid,'shipping_city',$city);
            }
            if ($iso!=='') {
                update_user_meta($uid,'billing_country',$iso);
                update_user_meta($uid,'shipping_country',$iso);
            }
        }
    }
};
add_action('fluentform_submission_inserted', $__hwpo_bridge_submit, 9, 3);
add_action('fluentform_after_submission', function($e,$d,$f) use ($__hwpo_bridge_submit){
    $__hwpo_bridge_submit($e,$d,$f);
}, 9, 3);

/* ============================================================
 * Billing Gate: helpers + AJAX + renderer (UI modal)
 * Menampilkan kartu "Billing Address" sebelum tombol Request Now
 * ============================================================ */

// 1) Helpers profil & validasi
if (!function_exists('hw_po_get_billing_profile')) {
  function hw_po_get_billing_profile($uid){
    $g = function($k) use($uid){ return trim((string)get_user_meta($uid, $k, true)); };
    return [
      'first_name'=>$g('billing_first_name'),
      'last_name' =>$g('billing_last_name'),
      'address_1' =>$g('billing_address_1'),
      'city'      =>$g('billing_city'),
      'state'     =>$g('billing_state'),
      'postcode'  =>$g('billing_postcode'),
      'phone'     =>$g('billing_phone'),
      'country'   =>$g('billing_country') ?: 'ID',
    ];
  }
}
/* ==== Billing snapshot (per ticket) ==== */
function hw_po_get_billing_snapshot($uid){
  $p = hw_po_get_billing_profile($uid);
  $p['ts'] = current_time('mysql'); // waktu WP
  return $p;
}
function hw_po_save_billing_snapshot($pid, $uid){
  if(!$pid || !$uid) return;
  $snap = hw_po_get_billing_snapshot($uid);
  update_post_meta($pid, 'hw_billing_snapshot', wp_json_encode($snap));
}
function hw_po_load_billing_snapshot($pid){
  $raw = get_post_meta($pid,'hw_billing_snapshot',true);
  $arr = $raw ? json_decode($raw,true) : [];
  if(!is_array($arr)) $arr = [];
  return $arr;
}

if (!function_exists('hw_po_billing_is_complete')) {
  function hw_po_billing_is_complete($p){
    foreach (['first_name','last_name','address_1','city','state','postcode','phone'] as $k){
      if (empty($p[$k])) return false;
    }
    return true;
  }
}

// 2) AJAX simpan/update billing (sinkron Woo + mirror shipping)
add_action('wp_ajax_hw_po_save_billing', function(){
  if (!is_user_logged_in()) wp_send_json_error(['message'=>'Please log in.'], 401);
  check_ajax_referer('hw_po_billing','nonce');
  $uid = get_current_user_id();

  $get = function($k){ return isset($_POST[$k]) ? trim((string)wp_unslash($_POST[$k])) : ''; };
  $data = [
    'billing_first_name' => $get('first_name'),
    'billing_last_name'  => $get('last_name'),
    'billing_address_1'  => $get('address_1'),
    'billing_city'       => $get('city'),
    'billing_state'      => strtoupper($get('state')),
    'billing_postcode'   => $get('postcode'),
    'billing_phone'      => preg_replace('/\s+/', '', $get('phone')),
    'billing_country'    => strtoupper($get('country') ?: 'ID'),
  ];
  foreach (['billing_first_name','billing_last_name','billing_address_1','billing_city','billing_state','billing_postcode','billing_phone'] as $k){
    if ($data[$k]==='') wp_send_json_error(['message'=>'Please complete all required fields.'], 400);
  }
  foreach($data as $k=>$v){ update_user_meta($uid,$k,$v); }

  // Mirror minimal → shipping_*
  $mirror = [
    'shipping_first_name'=>$data['billing_first_name'],
    'shipping_last_name' =>$data['billing_last_name'],
    'shipping_address_1' =>$data['billing_address_1'],
    'shipping_city'      =>$data['billing_city'],
    'shipping_state'     =>$data['billing_state'],
    'shipping_postcode'  =>$data['billing_postcode'],
    'shipping_phone'     =>$data['billing_phone'],
    'shipping_country'   =>$data['billing_country'],
  ];
  foreach($mirror as $k=>$v){ update_user_meta($uid,$k,$v); }

  $profile = hw_po_get_billing_profile($uid);
  wp_send_json_success(['profile'=>$profile, 'ok'=>hw_po_billing_is_complete($profile)]);
});
add_action('wp_ajax_nopriv_hw_po_save_billing', function(){
  wp_send_json_error(['message'=>'Please log in.'], 401);
});

// 3) Renderer UI (card + modal) — dipanggil dari shortcode
function hw_po_render_billing_gate(){
  if (!is_user_logged_in()) return '';
  $uid   = get_current_user_id();
  $p     = hw_po_get_billing_profile($uid);
  $nonce = wp_create_nonce('hw_po_billing');
  
  

  ob_start(); ?>
  <style>
    #hwpo-billing{margin:14px 0 10px}
    #hwpo-billing .card{border:1px solid #ffe3b3;background:#fffaf0; ...}
    #hwpo-billing[data-ok="1"] .card{border-color:#b7f3c6;background:#f2fff6}
    #hwpo-billing .badge--ok{background:#e8fff3;color:#047857}
    #hwpo-billing .badge--need{background:#fff1d6;color:#7a3e00}
    #hwpo-billing .badge--need:before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;background:#ff9900;margin-right:6px;box-shadow:0 0 0 0 rgba(255,153,0,.8);animation:hwpoPulse 1.8s infinite}
    @keyframes hwpoPulse{0%{box-shadow:0 0 0 0 rgba(255,153,0,.8)}70%{box-shadow:0 0 0 8px rgba(255,153,0,0)}100%{box-shadow:0 0 0 0 rgba(255,153,0,0)}}

    #hwpo-billing .actions{margin-left:auto;display:flex;gap:5px;flex-wrap:wrap}
    #hwpo-billing .btn{border:0;border-radius:10px;padding:5px 5px;font-weight:500;cursor:hover}
    #hwpo-billing .btn--edit{background:#3C6E71;color:#fff}
    #hwpo-billing .btn--save{background:#16a34a;color:#fff}
    #hwpo-billing .btn--use{background:#111827;color:#fff}
    .hwpo-modal2{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.32);backdrop-filter:blur(6px)}
    .hwpo-modal2.is-open{display:flex}
    .hwpo-modal2 .inner{background:#fff;border-radius:16px;box-shadow:0 30px 90px rgba(0,0,0,.28);width:min(760px,92vw);max-height:80vh;overflow:auto;padding:16px}
    .hwpo-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    @media(max-width:640px){ .hwpo-grid{grid-template-columns:1fr} }
    .hwpo-field label{display:block;font-weight:600;margin:2px 0 6px}
    .hwpo-field input{width:100%;border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px}
    .hwpo-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
  </style>

  <div id="hwpo-billing" data-ok="<?php echo hw_po_billing_is_complete($p)?'1':'0'; ?>">
    <div class="card">
      <div>
        <div style="font-weight:800;margin-bottom:4px">Billing Address</div>
        <div class="<?php echo hw_po_billing_is_complete($p)?'badge badge--ok':'badge badge--need'; ?>">
          <?php echo hw_po_billing_is_complete($p)?'Complete':'Need your attention'; ?>
        </div>
        <div id="hwpo-billing-preview" style="margin-top:6px;line-height:1.5">
          <?php if (hw_po_billing_is_complete($p)): ?>
            <?php echo esc_html($p['first_name'].' '.$p['last_name']); ?><br>
            <?php echo esc_html($p['address_1']); ?><br>
            <?php echo esc_html($p['city'].', '.$p['state'].' '.$p['postcode']); ?><br>
            <?php echo esc_html($p['phone']); ?>
          <?php else: ?>
            Please complete your billing address so we can process your pre-order.
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  
    <div class="hwpo-modal2" id="hwpo-billing-modal" aria-hidden="true">
      <div class="inner">
        <h3 style="margin-top:0">Edit Billing Address</h3>
        <form id="hwpo-billing-form">
          <div class="hwpo-grid">
            <div class="hwpo-field"><label>First name *</label><input name="first_name" value="<?php echo esc_attr($p['first_name']); ?>" required></div>
            <div class="hwpo-field"><label>Last name *</label><input name="last_name" value="<?php echo esc_attr($p['last_name']); ?>" required></div>
    
            <div class="hwpo-field" style="grid-column:1 / -1"><label>Street address *</label><input name="address_1" value="<?php echo esc_attr($p['address_1']); ?>" required></div>
    
            <div class="hwpo-field"><label>Town / City *</label><input name="city" value="<?php echo esc_attr($p['city']); ?>" required></div>
    
            <!-- Country / Region (NEW: select) -->
            <div class="hwpo-field">
              <label>Country / Region *</label>
              <select name="country" id="hwpo-bill-country" required></select>
            </div>
    
            <!-- Province / State (NEW: select, will switch to disabled when country has no states) -->
            <div class="hwpo-field">
              <label>Province / State *</label>
              <select name="state" id="hwpo-bill-state" required></select>
            </div>
    
            <div class="hwpo-field"><label>Postcode / ZIP *</label><input name="postcode" value="<?php echo esc_attr($p['postcode']); ?>" required></div>
            <div class="hwpo-field"><label>Phone *</label><input name="phone" value="<?php echo esc_attr($p['phone']); ?>" required></div>
          </div>
          <div class="hwpo-footer">
            <button type="button" class="btn" id="hwpo-billing-cancel">Cancel</button>
            <button type="submit" class="btn btn--save">Save Address</button>
          </div>
        </form>
      </div>
    </div>
    
    
    <script>
    (function(){
      var container = document.getElementById('hwpo-billing');
      if(!container) return;
    
      var modal = document.getElementById('hwpo-billing-modal');
      var form  = document.getElementById('hwpo-billing-form');
      var prev  = document.getElementById('hwpo-billing-preview');
      var selCountry = document.getElementById('hwpo-bill-country');
      var selState   = document.getElementById('hwpo-bill-state');
    
      function openM(){
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden','false');
        document.documentElement.style.overflow='hidden';
      }
      function closeM(){
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden','true');
        document.documentElement.style.overflow='';
      }
    
      // === Helpers pindah ke scope luar
      function fillCountries(countries, current){
        selCountry.innerHTML = '';
        Object.keys(countries||{}).forEach(function(code){
          var opt = document.createElement('option');
          opt.value = code;
          opt.textContent = countries[code];
          selCountry.appendChild(opt);
        });
        if(current){ selCountry.value = current; }
      }
      function fillStates(statesMap, country, current){
        selState.innerHTML = '';
        var list = (statesMap && statesMap[country]) ? statesMap[country] : null;
        if(list && Object.keys(list).length){
          Object.keys(list).forEach(function(sc){
            var opt = document.createElement('option');
            opt.value = sc;
            opt.textContent = list[sc] || sc;
            selState.appendChild(opt);
          });
          selState.disabled = false;
          if(current){ selState.value = current; }
        }else{
          var opt = document.createElement('option');
          opt.value = '';
          opt.textContent = '—';
          selState.appendChild(opt);
          selState.disabled = true;
        }
      }
      function primeBillingModal(){
        fetch('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>', {
          method:'POST',
          credentials:'same-origin',
          body: new URLSearchParams({action:'hw_po_get_billing'})
        })
        .then(function(r){ return r.json(); })
        .then(function(res){
          if(!res || !res.success) return;
    
          var v  = res.data.values || {};
          var cc = res.data.countries || {};
          var ss = res.data.states || {};
    
          var currentCountry = (v.billing_country || '<?php echo esc_js($p['country'] ?: 'ID'); ?>');
          fillCountries(cc, currentCountry);
          fillStates(ss, currentCountry, v.billing_state || '<?php echo esc_js($p['state']); ?>');
    
          selCountry.onchange = function(){
            fillStates(ss, selCountry.value, '');
          };
    
          // Prefill inputs; PHP prefills tetap jadi fallback
          form.elements['first_name'].value = v.billing_first_name || form.elements['first_name'].value || '';
          form.elements['last_name' ].value = v.billing_last_name  || form.elements['last_name' ].value || '';
          form.elements['address_1' ].value = v.billing_address_1  || form.elements['address_1' ].value || '';
          form.elements['postcode' ].value = v.billing_postcode   || form.elements['postcode' ].value || '';
          form.elements['phone'    ].value = v.billing_phone      || form.elements['phone'    ].value || '';
          // city tetap dari PHP (endpoint lama memang tidak mengirim city)
        });
      }
    
      // === Events tombol/modal
      document.getElementById('hwpo-billing-edit').addEventListener('click', function(){
        primeBillingModal();
        openM();
      });
      document.getElementById('hwpo-billing-cancel').addEventListener('click', closeM);
      modal.addEventListener('click', function(e){ if(e.target===modal) closeM(); });
    
      // === Submit form billing (AJAX save)
      form.addEventListener('submit', function(e){
        e.preventDefault();
        var fd = new FormData(form);
        fd.append('action','hw_po_save_billing');
        fd.append('nonce','<?php echo esc_js($nonce); ?>');
    
        fetch('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>', {
          method:'POST', credentials:'same-origin', body:fd
        })
        .then(function(r){ return r.json(); })
        .then(function(res){
          if(!res || !res.success){
            throw new Error((res && res.data && res.data.message) || 'Failed');
          }
          var p = res.data.profile || {};
          prev.innerHTML =
            (p.first_name||'')+' '+(p.last_name||'')+'<br>'+
            (p.address_1||'')+'<br>'+
            (p.city||'')+', '+(p.state||'')+' '+(p.postcode||'')+'<br>'+
            (p.phone||'');
          container.setAttribute('data-ok', res.data.ok ? '1' : '0');
          closeM();
          alert('Billing address saved.');
        })
        .catch(function(err){
          alert(err.message || 'Error');
        });
      });
    
      // === Cegah submit PO kalau billing belum lengkap
      function guardSubmit(e){
        var good = container.getAttribute('data-ok') === '1';
        if(!good){
          e.preventDefault();
          alert('Please complete your billing address first.');
          primeBillingModal(); // biar select & input kepopulasi dulu
          openM();
          return false;
        }
        return true;
      }
    
      // Hook tombol submit Fluent Forms & fallback form
      document.addEventListener('click', function(e){
        var t = e.target;
        if(!t) return;
        if(t.closest('.ff-btn-submit') || (t.matches('button[type="submit"]') && t.closest('.hwpo-form'))){
          guardSubmit(e);
        }
      });
    
      // Letakkan kartu sebelum tombol submit FF
      (function placeBeforeSubmit(){
        var wrap = document.getElementById('hw-po-wrap');
        if(!wrap) return;
        var submit = wrap.querySelector('.ff-btn-submit');
        var block  = document.getElementById('hwpo-billing');
        if(submit && block && submit.parentNode){
          submit.parentNode.parentNode.insertBefore(block, submit.parentNode);
        }
      })();
    })();
    </script>

  <?php
  return ob_get_clean();
}



/* =========================
 * Helpers umum
 * ========================= */
function hw_user_has_role($role_slug){ $u=wp_get_current_user(); return $u && is_array($u->roles) && in_array($role_slug,$u->roles,true); }
function hw_user_is_admin(){ return current_user_can('manage_options'); }
function hw_user_can_pos_dashboard(){ return ( is_user_logged_in() && ( hw_user_has_role(HW_POS_MANAGER_ROLE) || hw_user_is_admin() ) ); }
function hw_po_status_choices(){
  return [
    'New'               => 'New',
    'Under Review'      => 'Under Review',
    'Quoted'            => 'Quoted',
    'Maison Preparation'=> 'Maison Preparation',  // NEW
    'Production'        => 'Production',          // rename
    'Ready to Ship'     => 'Ready to Ship',
    'On the Way Home'   => 'On the Way Home',    // NEW
    'Arrived'           => 'Arrived',            // NEW (final)
    'Rejected'          => 'Rejected',           // final
  ];
}

function hw_po_is_final_status($s){
  return in_array($s, ['Arrived','Rejected'], true);
}

function hw_po_get_customer_email($pid){ return trim((string)get_post_meta($pid,'hw_cust_email',true)); }
function hw_po_get_assignee_email($pid){ $uid=intval(get_post_meta($pid,'hw_assignee',true)); if(!$uid)return''; $u=get_user_by('id',$uid); return ($u && !empty($u->user_email))?$u->user_email:''; }
function hw_po_append_history($pid,$from,$to,$reason=''){ $raw=get_post_meta($pid,'hw_status_history',true); $hist=[]; if($raw){ $d=json_decode($raw,true); if(is_array($d)) $hist=$d; } $uid=get_current_user_id(); $user=$uid?get_user_by('id',$uid):null; $hist[]=['ts'=>gmdate('Y-m-d\TH:i:s\Z'),'user_id'=>$uid,'user_name'=>$user?$user->display_name:'system','from'=>(string)$from,'to'=>(string)$to,'reason'=>(string)$reason]; update_post_meta($pid,'hw_status_history',wp_json_encode($hist)); }
function hw_po_notify_transition($pid,$from,$to,$reason=''){
  if(!HW_PO_INTERNAL_EMAILS && !HW_PO_NOTIFY_CUSTOMER) return;
  $sub=sprintf('[HW Pre-Order] Status: %s → %s (Ticket #%d)',$from?:'-',$to,$pid);
  $lines=[
    'Ticket   : #'.$pid.' — '.get_the_title($pid),
    'From→To  : '.($from?:'-').' → '.$to,
    'Reason   : '.($reason?:'-'),
    '--- Customer ---',
    'Name     : '.(get_post_meta($pid,'hw_cust_name',true)?:'-'),
    'Email    : '.(get_post_meta($pid,'hw_cust_email',true)?:'-'),
    'Phone    : '.(get_post_meta($pid,'hw_cust_phone',true)?:'-'),
    '--- Estimate ---',
    'Quote    : '.(get_post_meta($pid,'hw_est_quote',true)?:'-'),
    'Lead (d) : '.(get_post_meta($pid,'hw_est_lead',true)?:'-'),
    '--- Deposit ---',
    'Req Depo : '.(get_post_meta($pid,'hw_req_deposit',true)?'Yes':'No'),
    'Amount   : '.(get_post_meta($pid,'hw_deposit_amount',true)?:'-'),
    'Paid     : '.(get_post_meta($pid,'hw_deposit_paid',true)?:'-'),
    'Confirmed: '.(get_post_meta($pid,'hw_deposit_confirmed',true)?'Yes':'No'),
    '',
    'Dashboard: '.admin_url('post.php?post='.$pid.'&action=edit'),
  ];
  $to=[]; if(HW_PO_INTERNAL_EMAILS){ $to=array_filter(array_map('trim',explode(',',HW_PO_INTERNAL_EMAILS))); }
  $ass=hw_po_get_assignee_email($pid); if($ass){ $to[]=$ass; }
  if(HW_PO_NOTIFY_CUSTOMER){ $cust=hw_po_get_customer_email($pid); if($cust){ $to[]=$cust; } }
  $to=array_values(array_unique($to)); if(!$to) return;
  wp_mail($to,$sub,implode("\n",$lines));
}
function hw_po_change_status($pid,$new,$reason=''){
  if(get_post_type($pid)!=='preorder')return; $choices=hw_po_status_choices(); if(!isset($choices[$new]))return;
  $cur=get_post_meta($pid,'hw_po_status',true)?:'New'; if($cur===$new||hw_po_is_final_status($cur))return;
  update_post_meta($pid,'_hw_po_programmatic_change','1'); update_post_meta($pid,'hw_po_status',$new); delete_post_meta($pid,'_hw_po_programmatic_change');
  hw_po_append_history($pid,$cur,$new,$reason); hw_po_notify_transition($pid,$cur,$new,$reason);
}

/* === Helpers Fluent Forms entry & prefill === */
function hw_po_get_ff_entry_response($entry_id){
  global $wpdb;
  $entry_id = intval($entry_id);
  if(!$entry_id) return [];
  $t = $wpdb->prefix.'fluentform_submissions';
  $row = $wpdb->get_row($wpdb->prepare("SELECT response FROM {$t} WHERE id=%d", $entry_id));
  if(!$row) return [];
  $resp = json_decode($row->response, true);
  return is_array($resp) ? $resp : [];
}
/** Ambil profile pelanggan terakhir untuk prefill form. */
function hw_po_get_last_customer_profile($uid, $email){
  $out = ['names'=>'', 'phone'=>'', 'address_1'=>''];
  $args = [
    'post_type'=>'preorder','post_status'=>'any','posts_per_page'=>1,
    'orderby'=>'date','order'=>'DESC',
    'meta_query'=>['relation'=>'OR',
      ['key'=>'hw_customer_user_id','value'=>intval($uid)],
      ($email ? ['key'=>'hw_cust_email','value'=>$email] : ['key'=>'hw_cust_email','compare'=>'EXISTS'])
    ],
  ];
  $q = new WP_Query($args);
  if($q->have_posts()){
    $pid = is_object($q->posts[0]) ? $q->posts[0]->ID : $q->posts[0];
    $out['names'] = get_post_meta($pid,'hw_cust_name',true) ?: '';
    $out['phone'] = get_post_meta($pid,'hw_cust_phone',true) ?: '';
    $entry_id = intval(get_post_meta($pid,'hw_form_entry_id',true));
    if($entry_id){
      $ff = hw_po_get_ff_entry_response($entry_id);
      foreach(['names','phone','address_1'] as $k){ if(!empty($ff[$k])) $out[$k] = $ff[$k]; }
    }
  }
  foreach($out as $k=>$v){ if(!is_scalar($v)||trim((string)$v)==='') unset($out[$k]); }
  return $out;
}

/* ---- UI formatting helpers ---- */
function hw_fmt_idr($v){
  if($v === '' || $v === null) return '-';
  if(is_string($v)) { $n = preg_replace('/[^\d.-]/','',$v); }
  else { $n = $v; }
  if($n === '' || !is_numeric($n)) return esc_html((string)$v);
  return 'IDR '.number_format((float)$n, 0, ',', '.');
}
// Parse "10.000.000", "10,000,000", "IDR 10.000.000" → 10000000 (float/int)
function hw_po_parse_idr($v){
  if (is_numeric($v)) return (float)$v;
  if (!is_string($v)) return 0;
  $s = trim($v);
  // buang semua kecuali digit & titik/koma
  $s = preg_replace('/[^\d.,-]/', '', $s);
  // jika ada koma sebagai desimal, buang titik ribuan
  if (strpos($s, ',') !== false && substr_count($s, ',') === 1 && substr_count($s, '.') >= 1) {
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else {
    // format IDR tanpa desimal → buang semua non digit
    $s = preg_replace('/[^\d-]/', '', $s);
  }
  if ($s === '' || $s === '-' ) return 0;
  return (float)$s;
}

function hw_fmt_days($v){
  if($v === '' || $v === null) return '-';
  $n = (int)preg_replace('/[^\d-]/','',$v);
  return $n . ' day' . ($n==1?'':'s');
}
function hw_fmt_yesno($b){ return $b ? 'Yes' : 'No'; }
function hw_kv_row($label,$value,$strong=false){
  $val = ($value === '' || $value === null) ? '—' : $value;
  return '<tr><td>'.esc_html($label).'</td><td>'.($strong?'<strong>':'').esc_html($val).($strong?'</strong>':'').'</td></tr>';
}
function hw_kv_row_html($label,$html){
  $allowed = [
    'a'=>['href'=>true,'target'=>true,'rel'=>true,'title'=>true],
    'img'=>['src'=>true,'alt'=>true,'style'=>true,'width'=>true,'height'=>true,'loading'=>true],
    'br'=>[], 'strong'=>[], 'em'=>[], 'ul'=>[], 'ol'=>[], 'li'=>[], 'span'=>['style'=>true]
  ];
  $safe = wp_kses($html,$allowed);
  return '<tr><td>'.esc_html($label).'</td><td>'.$safe.'</td></tr>';
}

/* === Customer Summary block (per-status) === */
function hw_po_render_customer_summary_block($status, $args = []) {
  $defaults = [
    'est_quote' => '',
    'est_lead'  => '',
    'dep_req'   => false,
    'dep_amount'=> '',
    'dep_paid'  => '',
    'dep_conf'  => false,
  ];
  $a = array_merge($defaults, is_array($args)?$args:[]);

  // Format siap pakai
  $q   = ($a['est_quote'] !== '' ? hw_fmt_idr($a['est_quote']) : '—');
  $ld  = ($a['est_lead']  !== '' ? hw_fmt_days($a['est_lead']) : '—');
  $req = hw_fmt_yesno($a['dep_req']);
  $dam = ($a['dep_amount'] !== '' ? hw_fmt_idr($a['dep_amount']) : '—');
  $dpd = ($a['dep_paid']   !== '' ? hw_fmt_idr($a['dep_paid'])   : '—');
  $dco = hw_fmt_yesno($a['dep_conf']);

  // Narasi + baris tabel per status
  $intro = '';
  $rows  = [];

// ADD ↓ (replace entire switch block)
// ADD ↓ (replace entire switch block)
switch ($status) {
  case 'New':
    $intro = 'Thanks! Your ticket has been received and is currently <strong>New</strong>. Our team will review your request shortly.';
    $rows[] = hw_kv_row('Current Status', 'New', true);
    break;

  case 'Under Review':
    $intro = 'We are reviewing your request details. If we need more information, we will reach out via WhatsApp or email.';
    $rows[] = hw_kv_row('Current Status', 'Under Review', true);
    break;

  case 'Quoted':
    $intro = 'Your pre-order can be crafted exclusively for you. Here is a quick summary:';
    $rows[] = hw_kv_row('Quote (IDR)', $q, true);
    $rows[] = hw_kv_row('Lead Time',    $ld);
    $rows[] = hw_kv_row('Deposit Required', $req);
    break;

  case 'Maison Preparation':
    $intro = 'Deposit received — we are preparing materials and finalizing specifications.';
    $rows[] = hw_kv_row('Stage', 'Maison Preparation', true);
    $rows[] = hw_kv_row('Estimated Lead Time', $ld);
    break;

  case 'Production':
    $intro = 'Your order is now in production. We will update you again when it is ready to ship.';
    $rows[] = hw_kv_row('Stage', 'Production', true);
    $rows[] = hw_kv_row('Estimated Lead Time', $ld);
    break;

  case 'Ready to Ship':
    $intro = 'Your order is ready to ship. We will confirm shipping details and arrange the delivery.';
    $rows[] = hw_kv_row('Stage', 'Ready to Ship', true);
    break;

  case 'On the Way Home':
    $intro = 'Your order has been shipped and is on its way. We will notify you once it arrives.';
    $rows[] = hw_kv_row('Stage', 'On the Way Home', true);
    break;

  case 'Arrived':
    $intro = 'Delivered — thank you! If you need after-care or adjustments, feel free to contact us.';
    $rows[] = hw_kv_row('Stage', 'Arrived', true);
    break;

  case 'Rejected':
    $intro = 'We are sorry — we are unable to proceed with this request. You may submit a new brief for our review.';
    $rows[] = hw_kv_row('Stage', 'Rejected', true);
    break;

  default:
    $intro = 'Here is the latest summary for your ticket.';
    $rows[] = hw_kv_row('Current Status', $status ?: '—', true);
    break;
}


  $table =
    '<table class="hwv-kv" style="width:100%;margin-top:8px;border-collapse:collapse"><tbody>'.
      implode('', $rows).
    '</tbody></table>';

  $footer = '<div style="margin-top:6px;font-size:12px;opacity:.8">Production will begin after we receive your payment. Please review your order details below.</div>';

  // Untuk status sebelum Quoted, jangan tampilkan footer pembayaran
  $show_footer = !in_array($status, ['New','Under Review','Rejected'], true);

  return
    '<div class="hwv-quote" style="border:1px solid #eaeaea;background:#f9fafb;border-radius:14px;padding:12px 14px;margin:8px 0 14px;line-height:1.5">'.
      '<div>'. $intro .'</div>'.
      $table.
      ($show_footer ? $footer : '').
    '</div>';
}


function hw_po_pretty_label_from_key($key){
  $key = trim((string)$key);
  $key = preg_replace('/[_\-]+/',' ', $key);
  return ucwords($key);
}
function hw_po_is_image_url($url){
  $path = parse_url($url, PHP_URL_PATH);
  return (bool)preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)$/i', $path ?? '');
}
function hw_po_flatten_to_list($val){
  $out = [];
  $walker = function($v) use (&$out, &$walker){
    if(is_array($v)){
      foreach($v as $vv){ $walker($vv); }
    } else if(is_scalar($v)) {
      $s = trim((string)$v);
      if($s!=='') $out[] = $s;
    }
  };
  $walker($val);
  return $out;
}
function hw_po_hide_ff_field($key){
  $norm = strtolower(trim(preg_replace('/[^a-z0-9]+/','', (string)$key)));
  $black = [
    'fluentform6fluentformnonce','fluentformfluentformnonce','fluentformnonce',
    'wphttpreferer','_wphttpreferer','cfturnstileresponse',
    'fluentformembdedpostid','fluentformembeddedpostid','embeddedpostid','embdedpostid'
  ];
  $extra = apply_filters('hw_po_ff_hidden_keys', []);
  if (is_array($extra)) {
    foreach ($extra as $x) {
      $xnorm = strtolower(trim(preg_replace('/[^a-z0-9]+/','', (string)$x)));
      if ($xnorm) $black[] = $xnorm;
    }
  }
  if (in_array($norm, $black, true)) return true;
  if (preg_match('/fluentform.*nonce/i', $norm)) return true;
  if (preg_match('/^(?:_)?wphttpreferer$/i', $norm)) return true;
  if (preg_match('/cf.*turnstile.*response/i', $norm)) return true;
  if (preg_match('/(embedded|embded)postid/i', $norm)) return true;
  return false;
}

/** =========================
 * STAFF helper + auto-assign saat save preorder (admin/POS)
 * ========================= */
function hw_user_is_staff(){
  if (!is_user_logged_in()) return false;
  if (hw_user_is_admin()) return true;
  if (hw_user_has_role('shop_manager')) return true;
  if (hw_user_has_role('editor')) return true;
  if (hw_user_has_role(HW_POS_MANAGER_ROLE)) return true;
  return false;
}

/** Auto-assign ke user yang sedang login tiap kali preorder di-save oleh staff */
add_action('save_post_preorder', function($post_id, $post, $update){
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if (wp_is_post_revision($post_id)) return;
  if (!hw_user_is_staff()) return;
  $uid = get_current_user_id();
  if ($uid) update_post_meta($post_id, 'hw_assignee', intval($uid));
}, 10, 3);


/* ===== Map label Fluent Forms ===== */
function hw_po_canon_key($s){
  $s = strtolower((string)$s);
  $s = preg_replace('/[^a-z0-9]+/', '_', $s);
  $s = preg_replace('/_+/', '_', $s);
  return trim($s, '_');
}
function hw_po_get_ff_label_map($form_id){
  static $cache = [];
  $form_id = (int)$form_id;
  if(isset($cache[$form_id])) return $cache[$form_id];

  global $wpdb;
  $map = [];

  $t_forms = $wpdb->prefix.'fluentform_forms';
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_forms} WHERE id=%d", $form_id), ARRAY_A);
  $json = '';
  if ($row) {
    foreach (['form_fields','form_fields_json','fields','form_elements'] as $col) {
      if (!empty($row[$col])) { $json = $row[$col]; break; }
    }
  }
  if (!$json) {
    $t_meta = $wpdb->prefix.'fluentform_form_meta';
    $json = $wpdb->get_var($wpdb->prepare(
      "SELECT meta_value FROM {$t_meta}
       WHERE form_id=%d AND meta_key IN ('form_fields','_form_fields','form_elements','_form_elements')
       ORDER BY id DESC LIMIT 1", $form_id
    ));
    if (!$json) {
      $json = $wpdb->get_var($wpdb->prepare(
        "SELECT value FROM {$t_meta}
         WHERE form_id=%d AND meta_key IN ('form_fields','_form_fields','form_elements','_form_elements')
         ORDER BY id DESC LIMIT 1", $form_id
      ));
    }
  }

  $arr = [];
  if (is_array($json)) {
    $arr = $json;
  } elseif (is_string($json)) {
    $arr = json_decode($json, true);
    if (!is_array($arr)) {
      $arr = json_decode( wp_unslash($json), true );
      if (!is_array($arr)) $arr = [];
    }
  }

  $pick_label = function($node){
    $paths = [
      ['settings','admin_field_label'],
      ['attributes','admin_field_label'],
      ['settings','advanced_options','admin_field_label'],
      ['admin_field_label'],
      ['settings','admin_label'],
      ['settings','label'],
      ['attributes','label'],
      ['attributes','placeholder'],
    ];
    foreach ($paths as $path) {
      $tmp = $node;
      foreach ($path as $p) {
        if (!is_array($tmp) || !array_key_exists($p,$tmp)) { $tmp = null; break; }
        $tmp = $tmp[$p];
      }
      if (is_string($tmp) && trim($tmp)!=='') return trim($tmp);
    }
    return '';
  };

  $walk = function($nodes) use (&$walk, &$map, $pick_label){
    if (!is_array($nodes)) return;
    foreach ($nodes as $node) {
      if (!is_array($node)) continue;

      $rawName = '';
      if (!empty($node['attributes']['name'])) $rawName = (string)$node['attributes']['name'];
      elseif (!empty($node['name']))          $rawName = (string)$node['name'];

      if ($rawName !== '') {
        $label = $pick_label($node);
        if ($label !== '') {
          $canon = hw_po_canon_key($rawName);
          $map[$rawName] = $label;
          $map[$canon]   = $label;
          $map[str_replace('-', '_', strtolower($rawName))] = $label;
        }
      }

      foreach (['columns','fields','elements','children','tabs','step_items','rows','row','components','items','inner','sections'] as $k) {
        if (!empty($node[$k])) {
          if ($k==='columns') {
            foreach ($node['columns'] as $col) {
              if (!empty($col['fields'])) $walk($col['fields']);
            }
          } else {
            $walk($node[$k]);
          }
        }
      }
    }
  };
  $walk($arr);

  return $cache[$form_id] = apply_filters('hw_po_ff_label_map', $map, $form_id);
}
function hw_po_label_for_key($key, $form_id){
  $map   = hw_po_get_ff_label_map($form_id);
  $raw   = (string)$key;
  $canon = hw_po_canon_key($raw);

  if (isset($map[$raw])   && $map[$raw]   !== '') return $map[$raw];
  if (isset($map[$canon]) && $map[$canon] !== '') return $map[$canon];

  foreach ($map as $k=>$v) {
    if (strtolower($k)===strtolower($raw) && trim($v)!=='') return $v;
  }
  return hw_po_pretty_label_from_key($key);
}

/* === Redirect cookie helpers === */
if (!function_exists('hw_po_set_redirect_cookie')) {
  function hw_po_set_redirect_cookie($url){
    setcookie('hw_po_redirect_to', esc_url_raw($url), time()+900, '/', '', is_ssl(), true);
  }
  function hw_po_get_redirect_cookie(){
    return !empty($_COOKIE['hw_po_redirect_to']) ? esc_url_raw( wp_unslash($_COOKIE['hw_po_redirect_to']) ) : '';
  }
  function hw_po_clear_redirect_cookie(){
    setcookie('hw_po_redirect_to','', time()-3600, '/', '', is_ssl(), true);
    unset($_COOKIE['hw_po_redirect_to']);
  }
}

/* ==== NEW: URL helper (current) + Inline login renderer ==== */
function hw_po_current_url(){
  $scheme = is_ssl() ? 'https://' : 'http://';
  $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
  $uri    = $_SERVER['REQUEST_URI'] ?? '/';
  return $scheme . $host . $uri;
}

/**
 * LOGIN MODERN (2 kolom) + XS Social Login
 * - Menggunakan shortcode: [xs_social_login]
 * - Ubah provider/kelas/teks tombol di variabel $xs_shortcode jika perlu.
 */
function hw_po_render_inline_login($title = 'Login to Access Your Pre-Orders', $desc = 'Sign in or use one of the login options to view your ticket status.'){
  hw_po_set_redirect_cookie( hw_po_current_url() ); // setelah login balik ke sini

  // === XS Social Login ===
  $social = '';
  if (shortcode_exists('xs_social_login')) {
    // contoh lain:
    // $xs_shortcode = '[xs_social_login provider="facebook,twitter,github" class="hwpo-xssl" btn-text="Masuk dengan %s"]';
    $xs_shortcode = '[xs_social_login class="hwpo-xssl"]';
    $social = do_shortcode($xs_shortcode);
  } elseif (shortcode_exists('wordpress_social_login')) {
    $social = do_shortcode('[wordpress_social_login]');
  } elseif (shortcode_exists('nextend_social_login')) {
    $social = do_shortcode('[nextend_social_login]');
  }

  // === WP form (fallback / email-password) ===
  $wpform = wp_login_form([
    'echo'     => false,
    'redirect' => hw_po_current_url(),
    'remember' => true,
    'label_username' => __('Email / Username','hwpo'),
    'label_password' => __('Password','hwpo'),
    'label_remember' => __('Remember me','hwpo'),
    'label_log_in'   => __('Log In','hwpo'),
  ]);

  ob_start(); ?>
  <style>
    /* ====== BRAND TOKENS ====== */
    #hw-po-wrap{ --ink:#0f172a; --muted:#6b7280; --accent:#3C6E71; --brand:#ED1B76; }

    /* ====== WRAPPER ====== */
    #hw-po-wrap .hwpo-login-modern{
      display:grid; grid-template-columns: 1.2fr .8fr; gap:22px; align-items:stretch;
      border:1px solid #eef0f2; border-radius:24px; background:#fff; padding:22px;
      box-shadow:0 22px 50px rgba(16,24,40,.06);
    }
    @media (max-width: 880px){
      #hw-po-wrap .hwpo-login-modern{ grid-template-columns:1fr; padding:18px; }
    }

    /* ====== LEFT: form ====== */
    #hw-po-wrap .hwpo-login-modern .left h3{
      margin:0 0 6px; font-size:clamp(22px,3.4vw,30px); line-height:1.15; color:var(--ink); font-weight:800;
      letter-spacing:.2px;
    }
    #hw-po-wrap .hwpo-login-modern .left .lead{ margin:0 0 14px; color:var(--muted); }

    /* Social login generic style (XS Social Login) */
    #hw-po-wrap .hwpo-login-modern .social{ margin:8px 0 14px; }
    #hw-po-wrap .hwpo-login-modern .social .social-title{
      font-size:12px; text-transform:uppercase; letter-spacing:.12em; color:#94a3b8; margin-bottom:8px; font-weight:700;
    }
    #hw-po-wrap .hwpo-login-modern .social .hwpo-xssl,
    #hw-po-wrap .hwpo-login-modern .social .wp-social-login-widget,
    #hw-po-wrap .hwpo-login-modern .social .nsl-container{
      display:flex; flex-wrap:wrap; gap:10px;
    }
    /* tombol generik di dalam container sosial */
    #hw-po-wrap .hwpo-login-modern .social .hwpo-xssl a,
    #hw-po-wrap .hwpo-login-modern .social .hwpo-xssl button,
    #hw-po-wrap .hwpo-login-modern .social .wp-social-login-widget a.wp-social-login-provider,
    #hw-po-wrap .hwpo-login-modern .social .nsl-container .nsl-button{
      display:inline-flex; align-items:center; gap:10px; padding:10px 14px; border-radius:12px;
      border:1px solid #e5e7eb; background:#fff; text-decoration:none; font-weight:700; color:#111827;
      box-shadow:0 10px 24px rgba(0,0,0,.04); transition:transform .15s, box-shadow .2s, border-color .2s;
      cursor:pointer;
    }
    #hw-po-wrap .hwpo-login-modern .social .hwpo-xssl a:hover,
    #hw-po-wrap .hwpo-login-modern .social .hwpo-xssl button:hover,
    #hw-po-wrap .hwpo-login-modern .social .wp-social-login-widget a.wp-social-login-provider:hover,
    #hw-po-wrap .hwpo-login-modern .social .nsl-container .nsl-button:hover{
      transform:translateY(-1px); box-shadow:0 14px 36px rgba(0,0,0,.06); border-color:#dfe3e8;
    }

    /* Divider */
    #hw-po-wrap .hwpo-login-modern .divider{ position:relative; text-align:center; margin:6px 0 14px; }
    #hw-po-wrap .hwpo-login-modern .divider:before{ content:""; display:block; height:1px; background:#e5e7eb; position:absolute; inset:auto 0 50% 0; transform:translateY(-50%); }
    #hw-po-wrap .hwpo-login-modern .divider span{
      position:relative; background:#fff; padding:0 10px; font-size:12px; color:#94a3b8; font-weight:700; letter-spacing:.1em;
      text-transform:uppercase;
    }

    /* WP login form skin */
    #hw-po-wrap .hwpo-login-modern .wpform form p{ margin:0 0 12px; }
    #hw-po-wrap .hwpo-login-modern .wpform label{ display:block; margin:0 0 6px; color:#475569; font-weight:600; }
    #hw-po-wrap .hwpo-login-modern .wpform input[type="text"],
    #hw-po-wrap .hwpo-login-modern .wpform input[type="email"],
    #hw-po-wrap .hwpo-login-modern .wpform input[type="password"]{
      width:100%; border:1px solid #e5e7eb; border-radius:14px; padding:12px 14px; height:48px; outline:none;
      transition:border-color .2s, box-shadow .2s;
    }
    #hw-po-wrap .hwpo-login-modern .wpform input:focus{
      border-color:var(--accent);
      box-shadow:0 0 0 4px rgba(60,110,113,.15);
    }
    #hw-po-wrap .hwpo-login-modern .wpform .forgetmenot{ display:flex; align-items:center; gap:8px; }
    #hw-po-wrap .hwpo-login-modern .wpform .button-primary{
      border-radius:14px; padding:10px 16px; height:auto; font-weight:800; border:0; cursor:pointer;
      background:linear-gradient(135deg,var(--brand), #ff5ba3); color:#fff;
      box-shadow:0 12px 28px rgba(237,27,118,.25); transition:transform .15s, box-shadow .2s, opacity .2s;
    }
    #hw-po-wrap .hwpo-login-modern .wpform .button-primary:hover{
      transform:translateY(-1px); box-shadow:0 18px 40px rgba(237,27,118,.28);
    }

    #hw-po-wrap .hwpo-login-modern .note{ font-size:12px; color:#64748b; margin-top:10px }

    /* ====== RIGHT: brand panel ====== */
    #hw-po-wrap .hwpo-login-modern .right{
      position:relative; border-radius:18px; overflow:hidden; min-height:180px;
      background:
        radial-gradient(1200px 400px at -20% -30%, rgba(237,27,118,.20), transparent 60%),
        radial-gradient(800px 300px at 120% 120%, rgba(60,110,113,.25), transparent 60%),
        linear-gradient(145deg, #ffffff, #f9fafb);
      border:1px solid #eef0f2;
      display:flex; align-items:center; justify-content:center; padding:16px;
    }
    #hw-po-wrap .hwpo-login-modern .right .brandbox{ text-align:center; max-width:320px; }
    #hw-po-wrap .hwpo-login-modern .right .tag{
      display:inline-block; padding:6px 10px; border-radius:999px; font-size:12px; font-weight:800; letter-spacing:.08em;
      background:#fdf2f8; color:#9d174d; margin-bottom:10px;
    }
    #hw-po-wrap .hwpo-login-modern .right h4{ margin:6px 0 8px; font-size:18px; color:var(--ink); font-weight:800; }
    #hw-po-wrap .hwpo-login-modern .right ul{ list-style:none; padding:0; margin:8px 0 0; color:#475569; font-size:14px; text-align:left; }
    #hw-po-wrap .hwpo-login-modern .right ul li{ margin:6px 0; padding-left:22px; position:relative; }
    #hw-po-wrap .hwpo-login-modern .right ul li:before{
      content:""; position:absolute; left:0; top:7px; width:10px; height:10px; border-radius:3px; background:var(--accent);
      box-shadow:0 0 0 3px rgba(60,110,113,.15);
    }
  </style>

  <div class="hwpo-login-modern">
    <div class="left">
      <h3><?php echo esc_html($title); ?></h3>
      <p class="lead"><?php echo esc_html($desc); ?></p>

      <?php if ($social): ?>
        <div class="social">
          <div class="social-title">Quick Login</div>
          <?php echo $social; ?>
        </div>
      <?php endif; ?>

      <div class="divider"><span>OR</span></div>

      <div class="wpform"><?php echo $wpform; ?></div>
    </div>

    <div class="right" aria-hidden="true">
      <div class="brandbox">
        <span class="tag">Hayu Widyas</span>
        <h4>Easily manage all your pre-orders in one dashboard</h4>
        <ul>
          <li>Track your production status and timeline in real-time</li>
          <li>Keep every request organized and accessible anytime</li>
          <li>Your account privacy and data security are always protected</li>
        </ul>
      </div>
    </div>
  </div>
  <?php
  return ob_get_clean();
}

/* =========================
 * CPT & Admin columns
 * ========================= */
add_action('init', function(){
  register_post_type('preorder',[
    'labels'=>['name'=>'Pre-Orders','singular_name'=>'Pre-Order','menu_name'=>'Pre-Orders'],
    'public'=>false,'show_ui'=>true,'show_in_menu'=>true,'menu_icon'=>'dashicons-tickets',
    'supports'=>['title','author'],'has_archive'=>false,'rewrite'=>false
  ]);
});

/** =========================
 * NEW: child slug router (/pre-order/new-pre-order, /pre-order/my-pre-orders)
 * ========================= */
add_filter('query_vars', function($vars){ $vars[]='hwpo_view'; return $vars; });
add_action('init', function(){
  add_rewrite_tag('%hwpo_view%','([^&]+)');
  add_rewrite_rule('^'.HW_PO_BASE_SLUG.'/'.HW_PO_CHILD_NEW.'/?$', 'index.php?pagename='.HW_PO_BASE_SLUG.'&hwpo_view=form', 'top');
  add_rewrite_rule('^'.HW_PO_BASE_SLUG.'/'.HW_PO_CHILD_MY.'/?$',  'index.php?pagename='.HW_PO_BASE_SLUG.'&hwpo_view=list', 'top');
}, 9);

/** Redirect pola lama ?tab=my|new ke child slug baru */
add_action('template_redirect', function(){
  if(!is_page()) return; global $post; if(!$post) return;
  if($post->post_name===HW_PO_BASE_SLUG && isset($_GET['tab'])){
    $tab = strtolower(sanitize_text_field($_GET['tab']));
    if($tab==='my'){ wp_safe_redirect(hw_po_child_url(HW_PO_CHILD_MY)); exit; }
    if($tab==='new'){ wp_safe_redirect(hw_po_child_url(HW_PO_CHILD_NEW)); exit; }
  }
});

/* Flush rewrite di aktivasi/deaktivasi */
register_activation_hook(__FILE__, function(){
  // auto-create base pages
  $p=get_page_by_path(HW_PO_BASE_SLUG); $content_pre='[hw_preorder_block]';
  if($p){ if(empty($p->post_content)) wp_update_post(['ID'=>$p->ID,'post_content'=>$content_pre]); }
  else { wp_insert_post(['post_title'=>'Pre-Order','post_name'=>HW_PO_BASE_SLUG,'post_status'=>'publish','post_type'=>'page','post_content'=>$content_pre]); }

  $pos=get_page_by_path('po-pos'); $content_pos='[hw_po_pos]';
  if($pos){ if(empty($pos->post_content)) wp_update_post(['ID'=>$pos->ID,'post_content'=>$content_pos]); }
  else { wp_insert_post(['post_title'=>'PO POS','post_name'=>'po-pos','post_status'=>'publish','post_type'=>'page','post_content'=>$content_pos]); }

  // ensure rules exist
  add_rewrite_tag('%hwpo_view%','([^&]+)');
  add_rewrite_rule('^'.HW_PO_BASE_SLUG.'/'.HW_PO_CHILD_NEW.'/?$', 'index.php?pagename='.HW_PO_BASE_SLUG.'&hwpo_view=form', 'top');
  add_rewrite_rule('^'.HW_PO_BASE_SLUG.'/'.HW_PO_CHILD_MY.'/?$',  'index.php?pagename='.HW_PO_BASE_SLUG.'&hwpo_view=list', 'top');
  flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(); });

add_filter('manage_preorder_posts_columns',function($c){ return ['cb'=>$c['cb'],'title'=>'Ticket','hw_status'=>'Status','hw_customer'=>'Customer','hw_product'=>'Requested','hw_assignee'=>'Assignee','author'=>'Created By','date'=>'Date']; });
add_action('manage_preorder_posts_custom_column',function($col,$id){
  if($col==='hw_status'){ echo esc_html(get_post_meta($id,'hw_po_status',true)?:'New'); }
  elseif($col==='hw_customer'){ $n=get_post_meta($id,'hw_cust_name',true); $e=get_post_meta($id,'hw_cust_email',true); echo esc_html($n?:'—'); if($e) echo '<br><a href="mailto:'.esc_attr($e).'">'.esc_html($e).'</a>'; }
  elseif($col==='hw_product'){
    $title = get_the_title($id);
    $entry = intval(get_post_meta($id,'hw_form_entry_id',true));
    echo esc_html($title ?: ($entry ? 'PO — Entry #'.$entry : '—'));
  }
  elseif($col==='hw_assignee'){ $uid=intval(get_post_meta($id,'hw_assignee',true)); echo $uid?(esc_html((get_user_by('id',$uid)->display_name??'—'))):'—'; }
},10,2);

/* =========================
 * ACF field group untuk tracking
 * ========================= */
add_action('acf/init', function(){
  if(!function_exists('acf_add_local_field_group'))return;
  acf_add_local_field_group([
    'key'=>'group_hw_preorder','title'=>'Pre-Order Tracking','fields'=>[
      ['key'=>'hw_cust_name','label'=>'Customer Name','name'=>'hw_cust_name','type'=>'text'],
      ['key'=>'hw_cust_email','label'=>'Customer Email','name'=>'hw_cust_email','type'=>'email'],
      ['key'=>'hw_cust_phone','label'=>'Customer Phone','name'=>'hw_cust_phone','type'=>'text'],
      ['key'=>'hw_customer_user_id','label'=>'Customer WP User ID','name'=>'hw_customer_user_id','type'=>'number','readonly'=>1],
      ['key'=>'hw_req_product','label'=>'Requested Product','name'=>'hw_req_product','type'=>'text'],
      ['key'=>'hw_req_material','label'=>'Leather / Material','name'=>'hw_req_material','type'=>'text'],
      ['key'=>'hw_req_color','label'=>'Color','name'=>'hw_req_color','type'=>'text'],
      ['key'=>'hw_req_size','label'=>'Size','name'=>'hw_req_size','type'=>'text'],
      ['key'=>'hw_req_budget','label'=>'Budget','name'=>'hw_req_budget','type'=>'text'],
      ['key'=>'hw_req_refs','label'=>'Reference Links','name'=>'hw_req_refs','type'=>'textarea','rows'=>3],
      ['key'=>'hw_req_images','label'=>'Reference Images (URLs)','name'=>'hw_req_images','type'=>'textarea','rows'=>3],
      ['key'=>'hw_req_notes','label'=>'Design Notes','name'=>'hw_req_notes','type'=>'textarea','rows'=>4],
      ['key'=>'hw_po_status','label'=>'Status','name'=>'hw_po_status','type'=>'select','choices'=>hw_po_status_choices(),'default_value'=>'New','ui'=>1],
      ['key'=>'hw_priority','label'=>'Priority','name'=>'hw_priority','type'=>'select','choices'=>['Normal'=>'Normal','High'=>'High','Urgent'=>'Urgent'],'default_value'=>'Normal','ui'=>1],
      ['key'=>'hw_assignee','label'=>'Assignee','name'=>'hw_assignee','type'=>'user','role'=>['administrator','shop_manager','editor'],'return_format'=>'id'],
      ['key'=>'hw_est_quote','label'=>'Product Quote (IDR)','name'=>'hw_est_quote','type'=>'text'],
      ['key'=>'hw_est_lead','label'=>'Estimated Lead Time (days)','name'=>'hw_est_lead','type'=>'number'],
      ['key'=>'hw_req_deposit','label'=>'Request Deposit?','name'=>'hw_req_deposit','type'=>'true_false','ui'=>1,'default_value'=>0],
      ['key'=>'hw_deposit_amount','label'=>'Deposit Amount (IDR)','name'=>'hw_deposit_amount','type'=>'number','min'=>0,'step'=>1],
      ['key'=>'hw_deposit_paid','label'=>'Deposit Paid (IDR)','name'=>'hw_deposit_paid','type'=>'number','min'=>0,'step'=>1],
      ['key'=>'hw_deposit_confirmed','label'=>'Deposit Confirmed?','name'=>'hw_deposit_confirmed','type'=>'true_false','ui'=>1,'default_value'=>0],
      ['key'=>'hw_deposit_txid','label'=>'Deposit Tx / Ref','name'=>'hw_deposit_txid','type'=>'text'],
      ['key'=>'hw_deposit_date','label'=>'Deposit Date','name'=>'hw_deposit_date','type'=>'date_picker','display_format'=>'Y-m-d','return_format'=>'Y-m-d'],
      ['key'=>'hw_internal_notes','label'=>'Internal Notes','name'=>'hw_internal_notes','type'=>'textarea','rows'=>4],
      ['key'=>'hw_form_entry_id','label'=>'Fluent Forms Entry ID','name'=>'hw_form_entry_id','type'=>'number','readonly'=>1],
    ],
    'location'=>[[['param'=>'post_type','operator'=>'==','value'=>'preorder']]],
    'position'=>'normal','style'=>'default','active'=>true,'show_in_rest'=>0,
  ]);
});

/* =========================
 * Integrasi Fluent Forms → buat tiket
 * ========================= */
add_action('fluentform_submission_inserted', function($entryId,$formData,$form){
  try{
    if(intval($form->id)!==intval(HW_PO_FLUENT_FORM_ID)) return;

    // Ambil nilai dari shortcode product reference (POST)
    $ref = [];
    foreach (['req_product','req_product_id','req_product_title','req_product_link','req_product_image','req_product_dimensions'] as $k){
      if(isset($_POST[$k])) $ref[$k] = sanitize_text_field( wp_unslash($_POST[$k]) );
    }
    $reqProductDisp = $ref['req_product_title'] ?? ($formData['req_product'] ?? '');

    // Judul ringkas
    $uid=get_current_user_id();
    $bits=[];
    if($reqProductDisp)                 $bits[]=$reqProductDisp;
    if(!empty($formData['req_color']))  $bits[]=sanitize_text_field($formData['req_color']);
    if(!empty($formData['cust_name']))  $bits[]=sanitize_text_field($formData['cust_name']);
    $title='PO — Entry #'.intval($entryId);
    if($bits){ $title='PO - '.implode(' | ',$bits); }

    $pid=wp_insert_post(['post_type'=>'preorder','post_status'=>'publish','post_title'=>$title,'post_author'=>$uid?:0],true);
    if(is_wp_error($pid)){ error_log('[HW-PO] Failed create post: '.$pid->get_error_message()); return; }

    // Simpan info customer singkat
    foreach(['cust_name'=>'hw_cust_name','cust_email'=>'hw_cust_email','cust_phone'=>'hw_cust_phone'] as $ff=>$mk){
      if(isset($formData[$ff]) && is_scalar($formData[$ff])) update_post_meta($pid,$mk, wp_kses_post($formData[$ff]));
    }

    // Simpan referensi produk
    if($reqProductDisp) update_post_meta($pid,'hw_req_product',$reqProductDisp);
    foreach(['req_product_id','req_product_title','req_product_link','req_product_image','req_product_dimensions'] as $k){
      if(!empty($ref[$k])) update_post_meta($pid,'hw_'.$k,$ref[$k]);
    }

    update_post_meta($pid,'hw_po_status','New');
    update_post_meta($pid,'hw_form_entry_id',intval($entryId));
    if($uid){ update_post_meta($pid,'hw_customer_user_id',intval($uid)); }
    
    // Simpan snapshot billing (saat submit)
    if($uid){ hw_po_save_billing_snapshot($pid, $uid); }

    // Timpa response FF agar field custom ikut tersimpan di entry
    if($entryId){
      global $wpdb;
      $t = $wpdb->prefix.'fluentform_submissions';
      $row = $wpdb->get_row($wpdb->prepare("SELECT response FROM {$t} WHERE id=%d",$entryId), ARRAY_A);
      if($row){
        $resp = json_decode($row['response'], true); if(!is_array($resp)) $resp=[];
        if($reqProductDisp) $resp['req_product'] = $reqProductDisp;
        foreach($ref as $k=>$v){ $resp[$k] = $v; }
        $wpdb->update($t, ['response'=>wp_json_encode($resp)], ['id'=>$entryId]);
      }
    }

    hw_po_append_history($pid,'','New','Ticket created from Fluent Forms');
    hw_po_notify_transition($pid,'','New','Ticket created from Fluent Forms');

  }catch(\Throwable $e){ error_log('[HW-PO] Exception: '.$e->getMessage()); }
},10,3);

/* =========================
 * Shortcode pelanggan: [hw_my_preorders]
 * ========================= */
add_shortcode('hw_my_preorders', function(){
  if(!is_user_logged_in()){
    // Jika dipanggil di /pre-order/my-pre-orders (child slug), tampilkan inline login
    if ( is_page(HW_PO_BASE_SLUG) && get_query_var('hwpo_view') === 'list' ) {
      return hw_po_render_inline_login('Login to Access Your Pre-Orders','Access your Request list and live status by signing in');
    }
    return '<p>Please <a href="/account/">log in</a> to see your pre-orders.</p>';
  }

  $uid   = get_current_user_id();
  $u     = wp_get_current_user();
  $email = is_a($u,'WP_User') ? (string)$u->user_email : '';
  
  $q_meta = new WP_Query([
      'post_type'=>'preorder','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids',
      'meta_query'=>['relation'=>'OR',
        [ 'key'=>'hw_customer_user_id', 'value'=>$uid ],
        ( $email ? [ 'key'=>'hw_cust_email', 'value'=>$email ] : [ 'key'=>'hw_cust_email', 'compare'=>'EXISTS' ] )
      ],
      'no_found_rows' => true,
      'update_post_term_cache' => false,
      'ignore_sticky_posts' => true,
    ]);

  $ids_meta   = is_wp_error($q_meta) ? [] : $q_meta->posts;
  $q_author = new WP_Query([
      'post_type'=>'preorder','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','author'=>$uid,
      'no_found_rows'=>true,'update_post_term_cache'=>false,'ignore_sticky_posts'=>true,
      ]);

  $ids_author = is_wp_error($q_author) ? [] : $q_author->posts;
  $ids        = array_values(array_unique(array_merge($ids_meta, $ids_author)));

  if ($email && $ids_meta) {
    foreach ($ids_meta as $pid) {
      $bound = get_post_meta($pid,'hw_customer_user_id',true);
      $mail  = get_post_meta($pid,'hw_cust_email',true);
      if (!$bound && $mail && strcasecmp(trim($mail), trim($email)) === 0) {
        update_post_meta($pid,'hw_customer_user_id',$uid);
      }
    }
  }

  if (empty($ids)) {
    return '<div class="hw-preorders"><h3>My Pre-Orders</h3><p>Belum ada request pre-order.</p></div>';
  }
  
  $q = new WP_Query([
      'post_type'=>'preorder','post_status'=>'any','posts_per_page'=>50,'post__in'=>$ids,'orderby'=>'date','order'=>'DESC',
      'no_found_rows'=>true,'update_post_term_cache'=>false,'ignore_sticky_posts'=>true,
      ]);


  ob_start(); ?>
  <div class="hw-preorders"><h3 style="margin-top:0">My Pre-Orders</h3>
    <style>
    .hw-preorders .badge{
        display:inline-block; padding:4px 10px; border-radius:999px;
        font-size:12px; font-weight:800;
        
    }
    .hw-preorders .badge--slate   { background:#f1f5f9; color:#0f172a; }
    .hw-preorders .badge--blue    { background:#e8f1ff; color:#1d4ed8; }
    .hw-preorders .badge--indigo  { background:#ede9fe; color:#4338ca; }
    .hw-preorders .badge--amber   { background:#fff7e6; color:#b45309; }
    .hw-preorders .badge--purple  { background:#f5e9ff; color:#7e22ce; }
    .hw-preorders .badge--teal    { background:#e6fffb; color:#0f766e; }
    .hw-preorders .badge--emerald { background:#e8fff3; color:#047857; }
    .hw-preorders .badge--green   { background:#eaffea; color:#166534; }
    .hw-preorders .badge--rose    { background:#ffe9ee; color:#be123c; }

      .hw-preorders .btn{display:inline-block;padding:8px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#ED1B76;color:#fff;cursor:pointer;font-size:12px;transition:all .2s;white-space:nowrap}
      .hw-preorders .btn:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.06)}
      .hw-preorders .table-wrap{overflow:auto;-webkit-overflow-scrolling:touch}
      .hw-preorders table{width:100%;border-collapse:collapse;table-layout:fixed;min-width:680px}
      .hw-preorders th,.hw-preorders td{padding:12px;border-bottom:1px solid #eaeaea;text-align:left;vertical-align:top}
      .hw-preorders th:first-child,.hw-preorders td:first-child{width:72px}
      .hw-preorders th:nth-child(4),.hw-preorders td:nth-child(4){width:150px}
      .hw-preorders th:nth-child(5),.hw-preorders td:nth-child(5){width:150px}
      .hw-preorders td:nth-child(2){white-space:normal}
      .hw-preorders th:not(:nth-child(2)),.hw-preorders td:not(:nth-child(2)){white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      @media(max-width:720px){
        .hw-preorders th:nth-child(5),.hw-preorders td:nth-child(5){display:none}
        .hw-preorders table{min-width:520px}
      }
    </style>
    
    <?php
      // status → kelas warna untuk badge (sinkron dg POS & status lama)
      $status_colors = [
        'New'                => 'slate',
        'Under Review'       => 'blue',
        'Quoted'             => 'indigo',
        //'Waiting Deposit'    => 'amber',   // status lama di sebagian tiket
        'Maison Preparation' => 'amber',
        'Production'         => 'purple',
        'Ready to Ship'      => 'emerald',
        'On the Way Home'    => 'teal',
        'Arrived'            => 'green',
        'Rejected'           => 'rose',
      ];
    ?>


    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>Requested</th><th>Status</th><th>Submitted</th><th>Last Update</th><th></th></tr>
        </thead>
        <tbody>
          <?php while($q->have_posts()): $q->the_post(); $pid=get_the_ID();
            $title = get_the_title() ?: ('PO — Entry #'.intval(get_post_meta($pid,'hw_form_entry_id',true)));
            $st=get_post_meta($pid,'hw_po_status',true)?:'New'; ?>
            <tr>
              <td><?php echo esc_html($pid); ?></td>
              <td><a href="#" class="hw-po-open" data-id="<?php echo esc_attr($pid); ?>"><strong><?php echo esc_html($title); ?></strong></a></td>
              <?php $cls = 'badge--' . ($status_colors[$st] ?? 'slate'); ?>
              <td><span class="badge <?php echo esc_attr($cls); ?>" data-status="<?php echo esc_attr($st); ?>">
                  <?php echo esc_html($st); ?>
                  </span></td>

              <td><?php echo esc_html(get_the_date('Y-m-d H:i')); ?></td>
              <td><?php echo esc_html(get_the_modified_date('Y-m-d H:i')); ?></td>
              <td><a href="#" class="btn hw-po-open" data-id="<?php echo esc_attr($pid); ?>">View</a></td>
            </tr>
          <?php endwhile; wp_reset_postdata(); ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php
  return ob_get_clean();
});

/** =========================
 *  FALLBACK FORM
 *  ========================= */
function hw_po_handle_fallback_submission(){
  if($_SERVER['REQUEST_METHOD']!=='POST' || !isset($_POST['hw_po_fallback_submit'])) return [false,''];
  if(HW_PO_REQUIRE_LOGIN_FOR_SUBMIT && !is_user_logged_in()) return [false,'<div class="error">Please log in to submit.</div>'];
  if(!wp_verify_nonce($_POST['hw_po_fallback_nonce'] ?? '', 'hw_po_fallback')) return [false,'<div class="error">Nonce invalid.</div>'];

  $required=['cust_name','cust_email','cust_phone','req_product']; $errors=[];
  foreach($required as $r){ if(empty($_POST[$r])) $errors[]=$r; }
  if(!empty($_POST['hp'])) $errors[]='hp';
  if($errors){ return [false,'<div class="error">Please fill in the details: '.esc_html(implode(', ',$errors)).'</div>']; }

  $data=[]; $fields=['cust_name','cust_email','cust_phone','req_product','req_material','req_color','req_size','req_budget','req_refs','req_notes'];
  foreach($fields as $f){ $data[$f]=sanitize_textarea_field($_POST[$f] ?? ''); }
  $uid=get_current_user_id();
  $title='PO - '.$data['req_product'].($data['req_color']?' | '.$data['req_color']:'').' | '.$data['cust_name'];

  $pid=wp_insert_post(['post_type'=>'preorder','post_status'=>'publish','post_title'=>$title,'post_author'=>$uid],true);
  if(is_wp_error($pid)) return [false,'<div class="error">Failed to create order.</div>'];

  $MAP=['cust_name'=>'hw_cust_name','cust_email'=>'hw_cust_email','cust_phone'=>'hw_cust_phone','req_product'=>'hw_req_product','req_material'=>'hw_req_material','req_color'=>'hw_req_color','hw_req_size'=>'hw_req_size','req_size'=>'hw_req_size','req_budget'=>'hw_req_budget','req_refs'=>'hw_req_refs','req_notes'=>'hw_req_notes'];
  foreach($MAP as $k=>$mk){ if(isset($data[$k])) update_post_meta($pid,$mk,$data[$k]); }
  if($uid){ update_post_meta($pid,'hw_customer_user_id',$uid); }
  
  if($uid){ hw_po_save_billing_snapshot($pid, $uid); }

  update_post_meta($pid,'hw_po_status','New');
  hw_po_append_history($pid,'','New','Ticket created from fallback form');
  hw_po_notify_transition($pid,'','New','Ticket created from fallback form');

  return [true,'<div class="updated">Request received! Our team will review it shortly. (Ticket #'.intval($pid).').</div>'];
}
function hw_po_render_fallback_form(){
  list($ok,$notice)=hw_po_handle_fallback_submission();
  ob_start(); ?>
  <style>
    .hwpo-form{padding:0;margin:0;background:transparent;border:0}
    .hwpo-form .row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .hwpo-form label{display:block;margin-bottom:6px;font-weight:600}
    .hwpo-form input[type=text],
    .hwpo-form input[type=email],
    .hwpo-form input[type=number],
    .hwpo-form textarea{width:100%;padding:.6rem .75rem;border:1px solid #e5e7eb;border-radius:8px}
    .hwpo-form textarea{min-height:90px}
    @media(max-width:800px){ .hwpo-form .row{grid-template-columns:1fr} }
    .hwpo-form .error,.hwpo-form .updated{padding:.6rem .75rem;margin:.5rem 0;border-radius:6px}
    .hwpo-form .error{background:#fff7e6;border:1px solid #ffd591}
    .hwpo-form .updated{background:#ecfff1;border:1px solid #b6ffce}
  </style>
  <div class="hwpo-form">
    <h3>Custom Pre-Order</h3>
    <?php if(!is_user_logged_in() && HW_PO_REQUIRE_LOGIN_FOR_SUBMIT): ?>
      <div class="error">You can complete this form, but <strong>submission requires login</strong>. Your information will be securely stored temporarily.</div>
    <?php endif; ?>
    <?php echo $notice; ?>
    <form method="post">
      <?php wp_nonce_field('hw_po_fallback','hw_po_fallback_nonce'); ?>
      <input type="text" name="hp" value="" style="display:none!important" tabindex="-1" autocomplete="off">
      <div class="row">
        <div><label>Nama</label><input type="text" name="cust_name" required></div>
        <div><label>Email</label><input type="email" name="cust_email" required></div>
        <div><label>No. HP / WhatsApp</label><input type="text" name="cust_phone" required></div>
        <div><label>Produk yang diinginkan</label><input type="text" name="req_product" required></div>
        <div><label>Bahan / Leather</label><input type="text" name="req_material" placeholder="Saffiano / Nappa"></div>
        <div><label>Warna</label><input type="text" name="req_color" placeholder="Tan / Black"></div>
        <div><label>Ukuran</label><input type="text" name="req_size" placeholder="30×25×12 cm"></div>
        <div><label>Budget (IDR)</label><input type="number" name="req_budget" min="0" step="1"></div>
        <div style="grid-column:1/-1"><label>Referensi Link (satu per baris)</label><textarea name="req_refs" placeholder="https://…"></textarea></div>
        <div style="grid-column:1/-1"><label>Catatan Desain</label><textarea name="req_notes"></textarea></div>
      </div>
      <div style="margin-top:12px">
        <button type="submit" class="button button-primary">Kirim Request</button>
        <input type="hidden" name="hw_po_fallback_submit" value="1">
      </div>
    </form>
  </div>
  <?php
  return ob_get_clean();
}

/* =========================
 * Ajax: Detail tiket (modal)
 * ========================= */
add_action('wp_ajax_hw_po_view', function(){
  if(!is_user_logged_in()) wp_send_json_error(['message'=>'Please log in.'], 401);
  check_ajax_referer('hw_po_view','nonce');
  $pid = isset($_POST['id']) ? intval($_POST['id']) : 0;
  if(!$pid || get_post_type($pid)!=='preorder') wp_send_json_error(['message'=>'Invalid ticket.'], 400);

  $owner = intval(get_post_meta($pid,'hw_customer_user_id',true));
  if( get_current_user_id() !== $owner && !hw_user_can_pos_dashboard() && !hw_user_is_admin() ){
    wp_send_json_error(['message'=>'Forbidden'], 403);
  }

  $f = function($k) use($pid){ return get_post_meta($pid,$k,true); };
  $entry_id   = intval($f('hw_form_entry_id'));
  $req_table  = $entry_id ? hw_po_render_ff_entry_html($entry_id) : '<p>Belum ada data.</p>';

  $est_quote  = $f('hw_est_quote');
  $est_lead   = $f('hw_est_lead');

  $dep_req    = (bool)get_post_meta($pid,'hw_req_deposit',true);
  $dep_amount = $f('hw_deposit_amount');
  $dep_paid   = $f('hw_deposit_paid');
  $dep_conf   = (bool)get_post_meta($pid,'hw_deposit_confirmed',true);

  $est_table =
    '<table class="hwv-kv"><tbody>'.
      hw_kv_row('Quote (IDR)', $est_quote!==''?hw_fmt_idr($est_quote):'-', true).
      hw_kv_row('Lead Time',   $est_lead!==''?hw_fmt_days($est_lead):'-').
    '</tbody></table>';

  $dep_table =
    '<table class="hwv-kv"><tbody>'.
      hw_kv_row('Request?',  hw_fmt_yesno($dep_req)).
      hw_kv_row('Amount',    $dep_amount!==''?hw_fmt_idr($dep_amount):'-').
      hw_kv_row('Paid',      $dep_paid!==''?hw_fmt_idr($dep_paid):'-').
      hw_kv_row('Confirmed', hw_fmt_yesno($dep_conf)).
    '</tbody></table>';

  $hist_raw = get_post_meta($pid,'hw_status_history',true); $hist = $hist_raw?json_decode($hist_raw,true):[];
  $hist_html = '<ol class="hwv-timeline">';
  if(is_array($hist)){
    $hist = array_reverse($hist);
    foreach($hist as $h){
      $hist_html .= '<li><span class="t">'.esc_html($h['ts']??'').'</span> <span class="u">'.esc_html($h['user_name']??'system').'</span> <span class="st">'.esc_html($h['from']?:'-').' → <strong>'.esc_html($h['to']?:'-').'</strong></span> <span class="r">'.esc_html($h['reason']?:'').'</span></li>';
    }
  }
  $hist_html .= '</ol>';

  $status_colors = [
    'New'=>'slate','Under Review'=>'blue','Quoted'=>'indigo',
    'Maison Preparation'=>'amber','Production'=>'purple','Ready to Ship'=>'emerald',
    'On the Way Home'=>'teal','Arrived'=>'green','Rejected'=>'rose',
  ];

  $cur_status = $f('hw_po_status') ?: 'New';
  $cls = 'badge--' . ($status_colors[$cur_status] ?? 'slate');
  $status_badge = '<span class="badge ' . esc_attr($cls) . '">'. esc_html($cur_status) .'</span>';

  // === Summary + tombol bayar
  $title   = 'Ticket #'.$pid.' — '.get_the_title($pid);
  $quoted_states = ['Quoted','Maison Preparation','Production','Ready to Ship','On the Way Home','Arrived'];
  $show_summary  = in_array($cur_status, $quoted_states, true);

  $can_pay   = in_array($cur_status, ['Quoted','Maison Preparation','Production','Ready to Ship','On the Way Home'], true);
  $quote_num = hw_po_parse_idr($est_quote);
  $depo_num  = floatval($dep_amount);
  $show_pay_full    = $can_pay && $quote_num > 0;
  $show_pay_deposit = $can_pay && $dep_req && $depo_num > 0;

  $summary_html = hw_po_render_customer_summary_block($cur_status, [
    'est_quote'=>$est_quote,'est_lead'=>$est_lead,
    'dep_req'=>$dep_req,'dep_amount'=>$dep_amount,'dep_paid'=>$dep_paid,'dep_conf'=>$dep_conf,
  ]);

  $pay_block = '';
  if ($show_pay_full || $show_pay_deposit) {
    $pay_block .= '<div class="hwv-pay" style="margin:10px 0 14px;display:flex;gap:10px;flex-wrap:wrap">';
    if ($show_pay_full) {
      $pay_block .= '<a href="#" class="btn-pay" data-kind="full" data-id="'.esc_attr($pid).'"
        style="display:inline-block;padding:10px 14px;border-radius:12px;background:#0f766e;color:#fff;text-decoration:none;font-weight:800">
        Pay Full — '.esc_html(hw_fmt_idr($quote_num)).'</a>';
    }
    if ($show_pay_deposit) {
      $pay_block .= '<a href="#" class="btn-pay" data-kind="deposit" data-id="'.esc_attr($pid).'"
        style="display:inline-block;padding:10px 14px;border-radius:12px;background:#2563eb;color:#fff;text-decoration:none;font-weight:800">
        Pay Deposit — '.esc_html(hw_fmt_idr($depo_num)).'</a>';
    }
    $pay_block .= '</div>';
  }

  $ajax_url   = admin_url('admin-ajax.php');
  $view_nonce = wp_create_nonce('hw_po_view');

    // ====== RAKIT HTML MODAL (Order Summary = blok statis, bukan accordion) ======
  $html  = '';
  $html .= '<div class="hwv-head"><h2 style="margin:0 8px 0 0">Ticket #'.$pid.' — '.esc_html(get_the_title($pid)).'</h2>'.$status_badge.'</div>';

  // ORDER SUMMARY (selalu terbuka & non-dropdown)
  $html .= '<section class="hwv-sec" aria-label="Order Summary" style="margin:8px 0 12px">';
  $html .=   $summary_html;   // <— dari hw_po_render_customer_summary_block()
  $html .=   $pay_block;      // <— tombol Pay Full / Pay Deposit
  $html .= '</section>';

  // SISANYA tetap accordion: Estimate, Deposit, Request Details, History
  $html .= '<div class="hwv-acc">';

  // Estimate
  $html .=   '<button class="acc-btn" type="button" data-target="#acc-est" aria-expanded="false">'
            .'<span>Estimate</span><span class="sum">'.esc_html(hw_fmt_idr($est_quote?:0)).'</span>'
            .'<svg class="chev" viewBox="0 0 20 20" fill="currentColor"><path d="M6 8l4 4 4-4"/></svg></button>'
            .'<div id="acc-est" class="acc-panel" hidden>'.$est_table.'</div>';

  // Deposit
  $html .=   '<button class="acc-btn" type="button" data-target="#acc-dep" aria-expanded="false">'
            .'<span>Deposit</span><span class="sum">'.esc_html(hw_fmt_idr($dep_amount?:0)).'</span>'
            .'<svg class="chev" viewBox="0 0 20 20" fill="currentColor"><path d="M6 8l4 4 4-4"/></svg></button>'
            .'<div id="acc-dep" class="acc-panel" hidden>'.$dep_table.'</div>';

  // Request Details (FF entry)
  $html .=   '<button class="acc-btn" type="button" data-target="#acc-req" aria-expanded="false">'
            .'<span>Request Details</span>'
            .'<svg class="chev" viewBox="0 0 20 20" fill="currentColor"><path d="M6 8l4 4 4-4"/></svg></button>'
            .'<div id="acc-req" class="acc-panel" hidden>'.$req_table.'</div>';

  // History
  $html .=   '<button class="acc-btn" type="button" data-target="#acc-hist" aria-expanded="false">'
            .'<span>History</span>'
            .'<svg class="chev" viewBox="0 0 20 20" fill="currentColor"><path d="M6 8l4 4 4-4"/></svg></button>'
            .'<div id="acc-hist" class="acc-panel" hidden>'.$hist_html.'</div>';

  $html .= '</div>'; // .hwv-acc

  wp_send_json_success(['html'=>$html]);
});

/* ==== AJAX: billing snapshot (staff/admin/owner) ==== */
add_action('wp_ajax_hw_po_billing_snapshot', function(){
  if(!is_user_logged_in()) wp_send_json_error(['message'=>'Please log in.'], 401);
  check_ajax_referer('hw_po_view','nonce');

  $pid = isset($_POST['id']) ? intval($_POST['id']) : 0;
  if(!$pid || get_post_type($pid)!=='preorder') wp_send_json_error(['message'=>'Invalid ticket.'], 400);

  $owner = intval(get_post_meta($pid,'hw_customer_user_id',true));

  // izinkan: pemilik tiket, admin, POS manager, editor, shop_manager
  if( get_current_user_id() !== $owner && !hw_user_can_pos_dashboard() && !hw_user_is_admin() && !hw_user_has_role('shop_manager') && !hw_user_has_role('editor') ){
    wp_send_json_error(['message'=>'Forbidden'], 403);
  }

  $snap = hw_po_load_billing_snapshot($pid);

  // Fallback ke user_meta bila snapshot kosong (tiket lama)
  if(!$snap || empty($snap['first_name'].$snap['last_name'].$snap['address_1'].$snap['city'].$snap['state'].$snap['postcode'].$snap['phone'])){
    if($owner){
      $snap = hw_po_get_billing_profile($owner);
      $snap['ts'] = 'LIVE (user meta)';
    } else {
      $snap = [];
    }
  }

  if(!$snap){ wp_send_json_error(['message'=>'No billing information available.']); }

  // HTML sederhana untuk modal
  $lines = [
    '<strong>'.esc_html(($snap['first_name']??'').' '.($snap['last_name']??'')).'</strong>',
    esc_html($snap['address_1']??''),
    esc_html(($snap['city']??'').', '.($snap['state']??'').' '.($snap['postcode']??'')),
    esc_html('Phone: '.($snap['phone']??'')),
    esc_html('Country: '.($snap['country']??'')),
    '<span style="opacity:.7">Captured: '.esc_html($snap['ts']??'-').'</span>'
  ];
  $html = '<div style="line-height:1.6">'.implode('<br>', array_filter($lines)).'</div>';

  wp_send_json_success(['html'=>$html]);
});


// === GET current billing + options (countries/states)
add_action('wp_ajax_hw_po_get_billing', function () {
    if (!is_user_logged_in()) wp_send_json_error(['message'=>'Please log in.'], 401);

    $uid = get_current_user_id();
    $keys = ['billing_first_name','billing_last_name','billing_address_1','billing_postcode','billing_country','billing_state','billing_phone'];
    $val  = [];
    foreach ($keys as $k) { $val[$k] = (string) get_user_meta($uid, $k, true); }

    // Country/State lists from Woo
    $countries = [];
    $statesMap = [];
    if (function_exists('WC') && WC()->countries) {
        $countries = WC()->countries->get_countries();                   // [code => name]
        $all = WC()->countries->get_states();                            // [code => [state_code=>state_name]]
        $statesMap = is_array($all) ? $all : [];
    }

    wp_send_json_success([
        'values'    => $val,
        'countries' => $countries,
        'states'    => $statesMap
    ]);
});


add_action('wp_ajax_hw_po_paylink', function(){
  if (!is_user_logged_in()) wp_send_json_error(['message'=>'Please log in.'], 401);
  check_ajax_referer('hw_po_view','nonce');

  $pid  = isset($_POST['id']) ? intval($_POST['id']) : 0;
  $kind = isset($_POST['kind']) ? sanitize_text_field($_POST['kind']) : 'full';
  if (!$pid || get_post_type($pid)!=='preorder') wp_send_json_error(['message'=>'Invalid ticket.'], 400);

  // Hanya pemilik tiket / staff yang boleh
  $owner = intval(get_post_meta($pid,'hw_customer_user_id',true));
  if ( get_current_user_id() !== $owner && !hw_user_can_pos_dashboard() && !hw_user_is_admin() ) {
    wp_send_json_error(['message'=>'Forbidden'], 403);
  }

  // Ambil nominal
  $quote = hw_po_parse_idr( get_post_meta($pid,'hw_est_quote',true) );
  $depo  = floatval( get_post_meta($pid,'hw_deposit_amount',true) );
  $amount = ($kind==='deposit') ? $depo : $quote;

  if ($amount <= 0) wp_send_json_error(['message'=>'Invalid amount.'], 400);

  $url = hw_po_wc_get_or_create_payment_order($pid, $kind, $amount);
  if (!$url) wp_send_json_error(['message'=>'Failed to create order.'], 500);

  wp_send_json_success(['url'=>$url]);
});
add_action('wp_ajax_nopriv_hw_po_paylink', function(){
  wp_send_json_error(['message'=>'Please log in.'], 401);
});



add_action('wp_ajax_nopriv_hw_po_view', function(){
  wp_send_json_error(['message'=>'Please log in.'], 401);
});

/* =========================
 * Render isi FF entry → HTML tabel
 * ========================= */
function hw_po_render_ff_entry_html($entry_id){
  global $wpdb;
  $entry_id = intval($entry_id);
  if(!$entry_id) return '<p>Form entry not found.</p>';

  $t_sub = $wpdb->prefix.'fluentform_submissions';
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_sub} WHERE id=%d", $entry_id));
  if(!$row){ return '<p>Form entry not found.</p>'; }

  $form_id = intval($row->form_id);
  if($form_id !== intval(HW_PO_FLUENT_FORM_ID)){
    return '<p>This entry doesn’t match the expected form (ID '.intval(HW_PO_FLUENT_FORM_ID).').</p>';
  }

  $resp = json_decode($row->response, true);
  if(!is_array($resp)){ return '<p>No form data available.</p>'; }

  /* --- Cari EMAIL: dari response dulu, kalau tidak ada ambil dari meta tiket --- */
  $email = '';
  foreach (['cust_email','email','customer_email'] as $ek) {
    if (!empty($resp[$ek]) && is_scalar($resp[$ek])) {
      $email = trim((string)$resp[$ek]); break;
    }
  }
  if ($email === '') {
    $maybe = get_posts([
      'post_type'   => 'preorder',
      'post_status' => 'any',
      'meta_key'    => 'hw_form_entry_id',
      'meta_value'  => $entry_id,
      'numberposts' => 1,             // get_posts() pakai numberposts
      'fields'      => 'ids',         // minta langsung ID
    ]);
    if (!empty($maybe)) {
      $pid = is_object($maybe[0]) ? $maybe[0]->ID : (int)$maybe[0];
      if ($pid) {
        $email = (string) get_post_meta($pid, 'hw_cust_email', true);
      }
    }
  }

  /* --- Bangun tabel --- */
  $html = '<table class="hwv-kv"><tbody>';

  // Sisipkan email 1x di paling atas jika ada
  if ($email !== '') {
    $html .= hw_kv_row('Email', $email, true);
  }

    // Skip key tertentu agar email tidak dobel & key sistem tidak dirender
    $skip_keys = ['_submission_id','_entry_id','cust_email','email','customer_email'];
    
    foreach ($resp as $k => $v) {
      if (in_array($k, $skip_keys, true)) continue;
      if (hw_po_hide_ff_field($k)) continue;


    $label = hw_po_label_for_key($k, $form_id);

    if(is_array($v)){
      $flat = hw_po_flatten_to_list($v);
      if($flat && count(array_filter($flat, 'hw_po_is_image_url')) === count($flat)){
        $imgs = '<div class="hwv-imgs">';
        foreach($flat as $u){
          $u = esc_url($u);
          $imgs .= '<a href="'.$u.'" target="_blank" rel="noopener"><img src="'.$u.'" alt="" loading="lazy" style="border-radius:10px;border:1px solid #eee;"/></a>';
        }
        $imgs .= '</div>';
        $html .= hw_kv_row_html($label, $imgs);
      } else {
        $items = [];
        foreach($flat as $s){
          if(filter_var($s, FILTER_VALIDATE_URL)){
            $items[] = '<a href="'.esc_url($s).'" target="_blank" rel="noopener">'.esc_html($s).'</a>';
          } else {
            $items[] = esc_html($s);
          }
        }
        if ($items) {
          $html .= hw_kv_row_html($label, '<ul><li>'.implode('</li><li>',$items).'</li></ul>');
        }
      }
    } else {
      $s = trim((string)$v);
      if($s === '') continue;
      if(filter_var($s, FILTER_VALIDATE_URL)){
        $html .= hw_kv_row_html($label, '<a href="'.esc_url($s).'" target="_blank" rel="noopener">'.esc_html($s).'</a>');
      } else {
        $html .= hw_kv_row($label, $s);
      }
    }
  }

  $html .= '</tbody></table>';
  return $html;
}


add_filter('hw_po_ff_label_map', function($map, $form_id){
  $map['names'] = 'Full Name';
  $map['phone'] = 'Whatsapp Number';
  $map['address_1'] = 'Address';

  $map['multi_select'] = 'Genuine Materials';
  $map['dropdown'] = 'Tone Concept';
  $map['datetime'] = 'Expected Date';
  $map['image-upload_5'] = 'Design Reference';
  $map['image-upload_1'] = 'Color Reference';

  $map['dropdown_1'] = 'Interior Materials';
  $map['dropdown_2'] = 'Interior Type Concept';
  $map['image-upload_4'] = 'Front View';
  $map['image-upload_2'] = 'Side View';

  $map['image-upload_3'] = 'Detail / Other';
  $map['description'] = 'Design Notes';
  $map['numeric_field'] = 'Size Length';
  $map['numeric_field_1'] = 'Size Width';
  $map['numeric_field_2'] = 'Size Height';
  return $map;
}, 10, 2);

/** =========================
 *  Shortcode gabungan: [hw_preorder_block]
 *  ========================= */
add_shortcode('hw_preorder_block', function($atts = []) {
  $atts = shortcode_atts(['form_id'=>HW_PO_FLUENT_FORM_ID,'fallback'=>'0'], $atts, 'hw_preorder_block');
  $force_fallback = in_array(strtolower((string)$atts['fallback']), ['1','true','yes'], true);
  $form_id = intval($atts['form_id']) ?: intval(HW_PO_FLUENT_FORM_ID);

  $ffHtml = ''; $hasFF = false;
  if (!$force_fallback && shortcode_exists('fluentform')) {
    $ffHtml = do_shortcode('[fluentform id="'. $form_id .'"]');
    if ($ffHtml && (strpos($ffHtml,'ff-el-form')!==false || strpos($ffHtml,'name="fluentform_')!==false || preg_match('/<form[^>]+ff-?el-?form/i',$ffHtml))) $hasFF = true;
  }
  $html_form = $hasFF ? $ffHtml : hw_po_render_fallback_form();
  if (!$hasFF && current_user_can('manage_options')) {
    $html_form = '<div style="background:#fff7e6;border:1px solid #ffd591;padding:8px;border-radius:6px;margin-bottom:10px"><strong>Note:</strong> Menampilkan <em>fallback form</em> karena Fluent Forms ID '. esc_html($form_id) .' belum siap/terdeteksi.</div>' . $html_form;
  }

  $html_my    = do_shortcode('[hw_my_preorders]');
  $logged_in  = is_user_logged_in() ? 1 : 0;
  $user = wp_get_current_user();
  $email_curr = is_a($user,'WP_User') ? (string)$user->user_email : '';
  $prefill = is_user_logged_in() ? hw_po_get_last_customer_profile(get_current_user_id(), $email_curr) : [];

  /** NEW: tentukan mode view dari child slug (query var hwpo_view) */
  $view_q = get_query_var('hwpo_view');
  $view   = in_array($view_q, ['form','list'], true) ? $view_q : 'chooser';

  $ajax_url   = admin_url('admin-ajax.php');
  $view_nonce = wp_create_nonce('hw_po_view');

  $href_new = esc_url( hw_po_child_url(HW_PO_CHILD_NEW) );
  $href_my  = esc_url( hw_po_child_url(HW_PO_CHILD_MY) );
  $href_back= esc_url( hw_po_base_url() );
  
  // NEW: Login inline ketika belum login di child slugs
  $content_form = $logged_in ? $html_form : hw_po_render_inline_login('Login to Create Your Pre-Orders','Sign in or use one of the login options to view your ticket status.');
  // >>> ADD: inject Billing Gate sebelum tombol submit (hanya jika login)
  if ($logged_in) {
      $content_form .= hw_po_render_billing_gate();
  }
  $content_list = $logged_in ? $html_my   : hw_po_render_inline_login('Login to Access Your Pre-Orders','Sign in or use one of the login options to view your ticket status.');


  ob_start(); ?>
  <style>
    /* ====== PRE-ORDER: responsive + safe areas ====== */
    #hw-po-wrap{--accent:#3C6E71; --brand:#ED1B76; --ink:#0f172a; --radius:24px}

    /* chooser (2 kartu) */
    #hw-po-wrap .chooser{
      display:grid; grid-template-columns:repeat(2,minmax(0,1fr));
      gap:24px; margin:8px 0 18px;
    }
    @media (max-width:980px){ #hw-po-wrap .chooser{grid-template-columns:1fr} }

    /* big cards (now links) */
    #hw-po-wrap .bigcard{
      position:relative; border:0; border-radius:var(--radius);
      padding:28px 24px; min-height:150px; overflow:hidden; text-decoration:none;
      background:linear-gradient(135deg,var(--accent) 0%, rgba(60,110,113,.92) 60%, rgba(60,110,113,.75) 100%);
      color:#fff; box-shadow:0 22px 50px rgba(60,110,113,.25);
      display:grid; grid-template-columns:1fr auto; align-items:center; gap:16px;
      text-align:left; transition:transform .25s, box-shadow .25s, opacity .25s;
    }
    #hw-po-wrap .bigcard:hover{ transform:translateY(-2px); box-shadow:0 30px 60px rgba(60,110,113,.35) }
    #hw-po-wrap .bigcard .t{ font-size:clamp(18px,2.2vw,24px); font-weight:800; letter-spacing:.2px; line-height:1.1 }
    #hw-po-wrap .bigcard .d{ font-size:clamp(12px,1.6vw,14px); opacity:.9; margin-top:6px }
    #hw-po-wrap .bigcard .cta{
      display:inline-flex; align-items:center; justify-content:center;
      min-width:122px; padding:12px 14px; background:#fff; color:#222;
      border-radius:14px; font-weight:800; white-space:nowrap; border:0;
      box-shadow:0 8px 20px rgba(0,0,0,.12)
    }
    @media (max-width:480px){
      #hw-po-wrap .bigcard{ padding:22px 18px; min-height:136px }
      #hw-po-wrap .bigcard .cta{ position:absolute; right:16px; bottom:16px }
    }

    /* panel & backbar */
    #hw-po-wrap .panel{display:none}
    #hw-po-wrap .panel.is-active{display:block}
    #hw-po-wrap .card{border:1px solid #eef0f2;border-radius:20px;background:#fff;padding:18px;box-shadow:0 8px 28px rgba(17,24,39,.04)}
    #hw-po-wrap .backbar{display:none;margin:4px 0 16px}
    #hw-po-wrap .backbar.is-active{display:flex}
    #hw-po-wrap .backbar .backbtn{background:#fff;border:1px solid rgba(60,110,113,.25);color:#1f2937;border-radius:9999px;padding:8px 14px;font-weight:700;box-shadow:0 10px 24px rgba(60,110,113,.12);text-decoration:none}

    /* Modal, accordion, tables */
    #hw-po-wrap .hwpo-modal{position:fixed; inset:0; z-index:9999; display:flex; align-items:center; justify-content:center; padding:4svh 10px; background:rgba(15,23,42,.28); backdrop-filter:saturate(140%) blur(8px); -webkit-backdrop-filter:saturate(140%) blur(8px); opacity:0; pointer-events:none; transition:opacity .28s ease;}
    #hw-po-wrap .hwpo-modal.is-open{ opacity:1; pointer-events:auto; }
    #hw-po-wrap .hwpo-modal .inner{ position:relative; width:clamp(320px, 92vw, 680px); max-height:min(75svh, 740px); margin:auto; overflow:auto; background:#fff; border-radius:18px; box-shadow:0 28px 90px rgba(0,0,0,.28); transform:translateY(12px) scale(.98); transition:transform .32s cubic-bezier(.2,.8,.2,1), opacity .28s ease; padding:16px 16px 18px; }
    @media (min-width:640px){ #hw-po-wrap .hwpo-modal .inner{ width:clamp(480px, 70vw, 720px); max-height:min(78svh, 780px); border-radius:20px; padding:18px 18px 20px; } }
    @media (max-width:480px){ #hw-po-wrap .hwpo-modal .inner{ width:90vw; max-height:68svh; border-radius:14px; padding:12px; } }
    #hw-po-wrap .hwpo-modal .close{ position: sticky; top: 10px; display:flex; justify-content:flex-end; z-index:30; margin-bottom:8px }
    #hw-po-wrap .hwpo-modal .close .btn{ display:grid; place-items:center; width:36px; height:36px; padding:0; border:0; border-radius:9999px; cursor:pointer; background:var(--ink); color:#fff; line-height:1; font-size:18px; font-weight:700; box-shadow:0 12px 28px rgba(0,0,0,.25) }
    @media (max-width: 640px){ #hw-po-wrap .hwpo-modal .close .btn{ width:32px; height:32px; font-size:16px; } }
    #hw-po-wrap .badge{display:inline-block;padding:3px 8px;border-radius:12px;background:#eef2f7;font-size:12px}
    #hw-po-wrap .hwv-head{display:flex;align-items:center;gap:10px;margin:8px 0 12px}
    #hw-po-wrap .hwv-acc{display:grid;gap:10px}
    #hw-po-wrap .acc-btn{width:100%;display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:14px;padding:12px 14px;font-weight:700;cursor:pointer}
    #hw-po-wrap .acc-btn .sum{margin-left:auto;font-weight:600;font-size:12px;opacity:.75}
    #hw-po-wrap .acc-btn .chev{width:14px;height:14px;flex:0 0 14px;transform:rotate(-90deg);transition:transform .2s;opacity:.6}
    #hw-po-wrap .acc-btn[aria-expanded="true"] .chev{transform:rotate(0deg)}
    #hw-po-wrap .acc-panel{border:1px solid #eceff3;border-top:0;border-radius:0 0 14px 14px;padding:12px 14px;margin-top:-8px}
    #hw-po-wrap .hwv-kv{width:100%;border-collapse:collapse}
    #hw-po-wrap .hwv-kv td{padding:7px 8px;border-bottom:1px solid #f3f4f6;vertical-align:top}
    #hw-po-wrap .hwv-imgs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
    @media(max-width:640px){#hw-po-wrap .hwv-imgs{grid-template-columns:repeat(2,minmax(0,1fr))}}
    #hw-po-wrap .hwv-imgs img{width:100%;height:100%;object-fit:cover;border-radius:10px;border:1px solid #eee}
    #hw-po-wrap .hwv-timeline{list-style:none;padding-left:0;margin:0}
    #hw-po-wrap .hwv-timeline li{padding:8px 0;border-bottom:1px dashed #eee}
    #hw-po-wrap .hwv-timeline .t{opacity:.6;margin-right:6px}
    
    /* ===== Billing Card (attention states) ===== */
    #hw-po-wrap .bill-card{border:1px solid #ffe3b3;background:#fffaf0;border-radius:16px;padding:12px 14px;margin-top:12px;display:flex;gap:12px;align-items:flex-start;justify-content:space-between}
    #hw-po-wrap .bill-card.ready{border-color:#b7f3c6;background:#f2fff6}
    #hw-po-wrap .bill-card .info{flex:1 1 auto}
    #hw-po-wrap .bill-card h4{margin:0 0 4px;font-weight:800}
    #hw-po-wrap .bill-card p{margin:0;color:#666}
    #hw-po-wrap .bill-card .actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    #hw-po-wrap .badge-attn{display:inline-flex;align-items:center;gap:6px;background:#fff1d6;color:#7a3e00;border:1px solid #ffd494;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:800;margin-right:8px}
    #hw-po-wrap .badge-attn .dot{width:8px;height:8px;border-radius:50%;background:#ff9900;box-shadow:0 0 0 0 rgba(255,153,0,.8);animation:pulseDot 1.8s infinite}
    @keyframes pulseDot{0%{box-shadow:0 0 0 0 rgba(255,153,0,.7)}70%{box-shadow:0 0 0 8px rgba(255,153,0,0)}100%{box-shadow:0 0 0 0 rgba(255,153,0,0)}}
    #hw-po-wrap .badge-ready{display:inline-block;background:#dcffe8;color:#065f46;border:1px solid #b7f3c6;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:800;margin-right:8px}
    
    /* Buttons */
    #hw-po-wrap .btn-light{background:#e6f2ff;color:#0b5ed7;border:1px solid #cfe2ff;border-radius:12px;padding:10px 12px;font-weight:800;text-decoration:none;display:inline-block}
    #hw-po-wrap .btn-dark{background:#111827;color:#fff;border-radius:12px;padding:10px 12px;font-weight:800;text-decoration:none;display:inline-block}
    #hw-po-wrap .btn-primary{background:linear-gradient(135deg,var(--brand), #ff5ba3);color:#fff;border-radius:12px;padding:10px 14px;font-weight:900}
    
    /* Billing Modal */
    #hw-po-wrap .billing-modal .inner{max-width:760px}
    #hw-po-wrap .billing-form{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    #hw-po-wrap .billing-form .full{grid-column:1/-1}
    #hw-po-wrap .billing-form label{display:block;margin:0 0 6px;font-weight:700;color:#334155}
    #hw-po-wrap .billing-form input, #hw-po-wrap .billing-form select{
      width:100%;border:1px solid #e5e7eb;border-radius:12px;padding:10px 12px;height:44px;outline:none
    }
    #hw-po-wrap .billing-modal .footer{margin-top:10px;display:flex;gap:8px;justify-content:flex-end}

  </style>

  <div id="hw-po-wrap"
       data-logged-in="<?php echo esc_attr($logged_in); ?>"
       data-ajax-url="<?php echo esc_url($ajax_url); ?>"
       data-view-nonce="<?php echo esc_attr($view_nonce); ?>"
       data-view="<?php echo esc_attr($view); ?>"
       data-prefill="<?php echo esc_attr( wp_json_encode($prefill) ); ?>">

    <?php if ($view === 'chooser'): ?>
      <div class="chooser" id="hwpo-chooser">
        <a class="bigcard" href="<?php echo $href_new; ?>">
          <div><div class="t">New Pre-Order</div><div class="d">Start a new custom request</div></div>
          <div><span class="cta">Get Started →</span></div>
        </a>
        <a class="bigcard" href="<?php echo $href_my; ?>">
          <div><div class="t">My Pre-Orders</div><div class="d">Check the status of your tickets</div></div>
          <div><span class="cta">Open →</span></div>
        </a>
      </div>
    <?php else: ?>
      <div class="backbar is-active" id="hwpo-backbar"><a class="backbtn" href="<?php echo $href_back; ?>">← Back</a></div>
      <div id="hwpo-panel-form" class="panel <?php echo ($view==='form')?'is-active':''; ?>"><div class="card"><?php echo $view==='form' ? $content_form : ''; ?></div></div>
      <div id="hwpo-panel-list" class="panel <?php echo ($view==='list')?'is-active':''; ?>"><div class="card"><?php echo $view==='list' ? $content_list : ''; ?></div></div>
    <?php endif; ?>

    <div class="hwpo-modal" aria-hidden="true">
      <div class="inner">
        <div class="close"><button class="btn" type="button" aria-label="Close" title="Close" data-close>×</button></div>
        <div class="content">Loading…</div>
      </div>
    </div>
  </div>

  <script>
  (function(){
    var wrap = document.getElementById('hw-po-wrap');
    if(!wrap) return;
    var AJAX_URL = wrap.getAttribute('data-ajax-url');
    var VIEW_NONCE = wrap.getAttribute('data-view-nonce');
    var MODE = wrap.getAttribute('data-view') || 'chooser';

    // ===== Modal + accordion
    var modal = wrap.querySelector('.hwpo-modal');
    var modalContent = modal.querySelector('.content');
    function initModalUI(){
      var accButtons = modal.querySelectorAll('.acc-btn');
      accButtons.forEach(function(btn){
        btn.addEventListener('click', function(){
          var targetSel = btn.getAttribute('data-target');
          var panel = modal.querySelector(targetSel);
          var expanded = btn.getAttribute('aria-expanded') === 'true';
          accButtons.forEach(function(b){
            var p = modal.querySelector(b.getAttribute('data-target'));
            b.setAttribute('aria-expanded','false'); if(p) p.hidden = true;
          });
          btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
          if(panel) panel.hidden = expanded ? true : false;
        });
      });
      var first = modal.querySelector('.acc-btn');
      if(first && window.matchMedia('(min-width: 640px)').matches){ first.click(); }
        // ====== Handler tombol pembayaran (Pay Full / Pay Deposit) ======
        modal.querySelectorAll('.btn-pay').forEach(function(btn){
            btn.addEventListener('click', function(e){
                e.preventDefault();
                if (btn.dataset.busy === '1') return;     // ← guard anti double
                btn.dataset.busy = '1';
                var kind = btn.getAttribute('data-kind') || 'full';
                var id   = btn.getAttribute('data-id');
                if(!id){ btn.dataset.busy=''; return; }
                var oldHTML = btn.innerHTML;
                btn.setAttribute('disabled','disabled');
                btn.style.opacity = '.7';
                btn.innerHTML = 'Creating payment link…';
                var form = new FormData();
                form.append('action','hw_po_paylink');
                form.append('nonce', VIEW_NONCE);
                form.append('id', id);
                form.append('kind', kind);
                
                fetch(AJAX_URL, { method:'POST', credentials:'same-origin', body: form })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if(res && res.success && res.data && res.data.url){
                        window.location.href = res.data.url; // → /checkout/order-pay/… (Midtrans preselect di B)
                        } else {
                            throw new Error((res && res.data && res.data.message) ? res.data.message : 'Failed to create payment link.');
                        }
                })
                .catch(function(err){
                    alert(err && err.message ? err.message : 'Request failed.');
                    btn.removeAttribute('disabled');
                    btn.style.opacity = '1';
                    btn.innerHTML = oldHTML;
                    btn.dataset.busy = '';
                });
            });
        });
    }
    
    function openModal(html){ modalContent.innerHTML = html || '…'; modal.classList.add('is-open'); modal.setAttribute('aria-hidden','false'); document.documentElement.style.overflow='hidden'; initModalUI(); }
    function closeModal(){ modal.classList.remove('is-open'); modal.setAttribute('aria-hidden','true'); document.documentElement.style.overflow=''; }
    modal.querySelector('.close .btn').addEventListener('click', closeModal);
    modal.addEventListener('click', function(e){ if(e.target===modal) closeModal(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });

    // Prefill ringan (hanya jika ada form di halaman)
    if (MODE === 'form') {
      var PREFILL = {};
      try { PREFILL = JSON.parse(wrap.getAttribute('data-prefill') || '{}'); } catch(e){}
      function applyPrefillToForm(form){
        if(!PREFILL || !Object.keys(PREFILL).length) return;
        var map = {
          names:     ['names','cust_name','full_name','fullname'],
          phone:     ['phone','cust_phone','whatsapp','whatsapp_number'],
          address_1: ['address_1','address','city','address_line1']
        };
        Object.keys(map).forEach(function(key){
          var val = PREFILL[key]; if(!val) return;
          map[key].forEach(function(name){
            var el = form.querySelector('[name="'+name+'"]');
            if(el && !el.value){
              el.value = val;
              try{ el.dispatchEvent(new Event('input',{bubbles:true})); el.dispatchEvent(new Event('change',{bubbles:true})); }catch(e){}
            }
          });
        });
      }
      var forms = wrap.querySelectorAll('form');
      forms.forEach(applyPrefillToForm);
      setTimeout(function(){ forms.forEach(applyPrefillToForm); }, 300);
    }

    // Open ticket detail (AJAX modal) pada view list
    if (MODE === 'list') {
      wrap.addEventListener('click', function(e){
        var t = e.target.closest('.hw-po-open'); if(!t) return; e.preventDefault();
        var id = t.getAttribute('data-id'); if(!id) return;
        modalContent.innerHTML='Loading…'; modal.classList.add('is-open'); modal.setAttribute('aria-hidden','false'); document.documentElement.style.overflow='hidden';
        var form = new FormData(); form.append('action','hw_po_view'); form.append('nonce', VIEW_NONCE); form.append('id', id);
        fetch(AJAX_URL, { method:'POST', credentials:'same-origin', body: form })
          .then(function(r){ return r.json(); })
          .then(function(res){ if(res && res.success){ modalContent.innerHTML = res.data.html; initModalUI(); } else { modalContent.innerHTML = '<p style="color:#d00">'+(res && res.data && res.data.message ? res.data.message : 'Failed.')+'</p>'; } })
          .catch(function(){ modalContent.innerHTML = '<p style="color:#d00">Request failed.</p>'; });
      });
    }
  })();
  </script>
  <?php
  return ob_get_clean();
});

/** =========================
 *  Halaman auto-buat + Gate halaman (POS)
 *  ========================= */
add_action('template_redirect', function(){
  if(!is_page()) return; global $post; if(!$post) return;
  if($post->post_name==='po-pos' && !hw_user_can_pos_dashboard()){ status_header(403); wp_die('Forbidden: POS access only.'); }
});

/** =========================
 *  AUTO-TRANSITIONS
 *  ========================= */
add_action('updated_post_meta',function($mid,$pid,$key,$val){ if(get_post_type($pid)!=='preorder'||$key!=='hw_est_quote')return; $q=is_string($val)?trim($val):$val; if($q===''||$q===null)return; hw_po_change_status($pid,'Quoted','Estimated quote filled'); },10,4);
add_action('added_post_meta',function($mid,$pid,$key,$val){ if(get_post_type($pid)!=='preorder'||$key!=='hw_est_quote')return; $q=is_string($val)?trim($val):$val; if($q===''||$q===null)return; hw_po_change_status($pid,'Quoted','Estimated quote filled'); },10,4);


function hw_po_maybe_to_maison_preparation($pid){
  if (get_post_type($pid) !== 'preorder') return;

  $amt  = floatval(get_post_meta($pid,'hw_deposit_amount',true));
  $paid = floatval(get_post_meta($pid,'hw_deposit_paid',true));
  $conf = (bool) get_post_meta($pid,'hw_deposit_confirmed',true);
  $cur  = get_post_meta($pid,'hw_po_status',true) ?: 'New';

  // Deposit OK ⇒ pindah ke Maison Preparation
  if (($conf || ($amt > 0 && $paid >= $amt)) && $cur !== 'Maison Preparation' && !hw_po_is_final_status($cur)) {
    hw_po_change_status($pid,'Maison Preparation', $conf ? 'Deposit confirmed' : 'Deposit paid ≥ amount');
  }
}

/**
 * Setelah pembayaran berhasil, sinkronkan ke tiket Pre-Order.
 * Menangkap baik "payment complete" maupun perubahan status ke processing/completed.
 */
function hw_po_sync_after_payment($order_id){
  $order = wc_get_order($order_id);
  if (!$order) return;

  $pid  = intval($order->get_meta('_hw_po_ticket_id'));       // ID tiket preorder
  $kind = $order->get_meta('_hw_po_payment_type') ?: 'full';  // 'deposit' | 'full'
  if (!$pid || get_post_type($pid) !== 'preorder') return;

  // Nominal yang benar-benar dibayar (total order)
  $paid_amount = floatval($order->get_total());
  $txid        = (string) $order->get_transaction_id();

  // Akumulasi dibayar + konfirmasi deposit + tanggal
  $prev_paid = floatval(get_post_meta($pid,'hw_deposit_paid',true));
  $new_paid  = $prev_paid + $paid_amount;

  update_post_meta($pid, 'hw_deposit_paid',       (int)round($new_paid));
  update_post_meta($pid, 'hw_deposit_confirmed',  1);
  if ($txid) update_post_meta($pid, 'hw_deposit_txid', $txid);
  update_post_meta($pid, 'hw_deposit_date',       current_time('Y-m-d'));

  // Untuk pembayaran "full", jika belum ada deposit amount, set agar ringkas di UI
  if ($kind === 'full') {
    $quote = floatval(get_post_meta($pid,'hw_est_quote',true));
    if ($quote > 0) {
      // set deposit_amount = quote agar summary konsisten
      update_post_meta($pid, 'hw_deposit_amount', (int)round($quote));
    }
  }

  // Tulis catatan pada order
  $order->add_order_note(sprintf(
    'Synced to PO Ticket #%d (%s payment). Amount: %s',
    $pid, ucfirst($kind), wc_price($paid_amount)
  ));
  $order->save();

  // Evaluasi transisi status otomatis (fungsi kamu akan pindah ke "Maison Preparation" jika syarat terpenuhi)
  if (function_exists('hw_po_maybe_to_maison_preparation')) {
    hw_po_maybe_to_maison_preparation($pid);
  }
}
// Tertembak di mayoritas gateway saat payment selesai
add_action('woocommerce_payment_complete', 'hw_po_sync_after_payment', 10, 1);
// Cadangan bila gateway langsung set ke processing/completed
add_action('woocommerce_order_status_processing', function($order_id){ hw_po_sync_after_payment($order_id); }, 10, 1);
add_action('woocommerce_order_status_completed',  function($order_id){ hw_po_sync_after_payment($order_id); }, 10, 1);


add_action('updated_post_meta', function($mid,$pid,$key,$val){
  if (get_post_type($pid)!=='preorder') return;
  if (in_array($key,['hw_deposit_paid','hw_deposit_confirmed','hw_deposit_amount'], true)) {
    hw_po_maybe_to_maison_preparation($pid);
  }
}, 10, 4);
add_action('added_post_meta', function($mid,$pid,$key,$val){
  if (get_post_type($pid)!=='preorder') return;
  if (in_array($key,['hw_deposit_paid','hw_deposit_confirmed','hw_deposit_amount'], true)) {
    hw_po_maybe_to_maison_preparation($pid);
  }
}, 10, 4);

/**
 * Buat atau kembalikan WooCommerce order "pending" untuk tiket + tipe ('full'|'deposit').
 * Total akan diset sesuai $amount. Mengembalikan URL pembayaran checkout.
 */
function hw_po_wc_get_or_create_payment_order($pid, $type, $amount){
  if (!function_exists('wc_create_order')) return '';
  $pid  = intval($pid);
  $type = ($type === 'deposit') ? 'deposit' : 'full';
  $amount = max(0, floatval($amount));

  // Cek apakah sudah ada order pending untuk kombinasi ini
  $args = [
    'limit'      => 1,
    'status'     => ['pending','failed'],
    'type'       => 'shop_order',
    'return'     => 'ids',
    'meta_query' => [
      ['key'=>'_hw_po_ticket_id', 'value'=>$pid],
      ['key'=>'_hw_po_payment_type', 'value'=>$type],
    ],
  ];
  $orders = wc_get_orders($args);
  if (!empty($orders)) {
    $order = wc_get_order($orders[0]);
    $url = $order->get_checkout_payment_url();
    return add_query_arg('hwpo','1', $url);
    if ($order) {
      // Pastikan total sama; jika berubah, update total
      if (abs(floatval($order->get_total()) - $amount) > 0.01) {
        foreach ($order->get_items() as $item_id => $item) {
          $order->remove_item($item_id);
        }
        $item = new WC_Order_Item_Fee();
        $item->set_name( sprintf('Pre-Order #%d — %s Payment', $pid, ucfirst($type)) );
        $item->set_amount($amount);
        $item->set_total($amount);
        $order->add_item($item);
        $order->calculate_totals(true);
        $order->save();
      }
      return $order->get_checkout_payment_url();
    }
  }
  
  // Helper: dapatkan gateway id Midtrans bila tersedia
  function hw_po_find_midtrans_gateway_id(){
      if (!class_exists('WC_Payment_Gateways')) return '';
      $gws = WC()->payment_gateways() ? WC()->payment_gateways->get_available_payment_gateways() : [];
      foreach ($gws as $id => $gw) {
        if (stripos($id, 'midtrans') !== false) return $id; // cocokkan 'midtrans', 'midtrans_snap', dll.
      }
      return '';
  }
  /**
  ** * Pada halaman order-pay untuk order Pre-Order, tampilkan HANYA Midtrans.
  */
  add_filter('woocommerce_available_payment_gateways', function($gateways){
      if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) return $gateways;
      
      $order_id = absint(get_query_var('order-pay'));
      if (!$order_id) return $gateways;
      $order = wc_get_order($order_id);
      if (!$order) return $gateways;
    
      // Hanya jika order ini dibuat dari modul Pre-Order kita
      if (!$order->get_meta('_hw_po_ticket_id')) return $gateways;
    
      $mid = hw_po_find_midtrans_gateway_id();
      if (!$mid) return $gateways; // biarkan default jika Midtrans tidak aktif
    
      // Sisakan hanya Midtrans
      foreach ($gateways as $id => $gw) {
        if ($id !== $mid) unset($gateways[$id]);
      }
      return $gateways;
      
  }, 10);
  
  /**
  ** Jika tetap ada lebih dari satu gateway, paksa default = Midtrans pada order-pay kita.
  */
  add_filter('woocommerce_default_gateway', function($default){
      if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) return $default;
    
      $order_id = absint(get_query_var('order-pay'));
      $order = $order_id ? wc_get_order($order_id) : false;
      if (!$order || !$order->get_meta('_hw_po_ticket_id')) return $default;
    
      $mid = hw_po_find_midtrans_gateway_id();
      return $mid ?: $default;
  }, 10);


  // Buat order baru
  $order = wc_create_order();
  if (!$order || is_wp_error($order)) return '';

  // Customer (kalau ada)
  $uid = intval(get_post_meta($pid, 'hw_customer_user_id', true));
  if ($uid) { $order->set_customer_id($uid); }

  // Item fee dengan nominal bebas
  $fee = new WC_Order_Item_Fee();
  $fee->set_name( sprintf('Pre-Order #%d — %s Payment', $pid, ucfirst($type)) );
  $fee->set_amount($amount);
  $fee->set_total($amount);
  $order->add_item($fee);

  // Meta penghubung
  $order->update_meta_data('_hw_po_ticket_id', $pid);
  $order->update_meta_data('_hw_po_payment_type', $type);

  $order->calculate_totals(true);
  $order->save();
  // Preselect gateway Midtrans bila tersedia (nama id bervariasi antar plugin)
  $gws = WC()->payment_gateways() ? WC()->payment_gateways->get_available_payment_gateways() : [];
  if (!empty($gws)) {
      foreach ($gws as $gwid => $gw) {
          if (stripos($gwid, 'midtrans') !== false) {   // contoh: 'midtrans', 'midtrans_snap', dll.
          $order->set_payment_method($gw);
          break;
          }
      }
  }
  $order->save();


  return $order->get_checkout_payment_url();
}




add_filter('pre_update_post_meta',function($chk,$pid,$key,$val){ if($key!=='hw_po_status'||get_post_type($pid)!=='preorder')return $chk; if(get_post_meta($pid,'_hw_po_programmatic_change',true))return $chk; $old=get_post_meta($pid,'hw_po_status',true)?:'New'; set_transient('hw_po_prev_status_'.$pid,$old,60); return $chk; },10,4);
add_action('updated_post_meta',function($mid,$pid,$key,$val){ if($key!=='hw_po_status'||get_post_type($pid)!=='preorder')return; if(get_post_meta($pid,'_hw_po_programmatic_change',true))return; $from=get_transient('hw_po_prev_status_'.$pid); delete_transient('hw_po_prev_status_'.$pid); $to=is_string($val)?$val:get_post_meta($pid,'hw_po_status',true); if(!$to)$to='New'; if($from===$to)return; hw_po_append_history($pid,$from,$to,'Manual change'); hw_po_notify_transition($pid,$from,$to,'Manual change'); },10,4);

/** =========================
 *  Admin Filter + CSV Export
 *  ========================= */
add_action('restrict_manage_posts',function($pt){
  if($pt!=='preorder')return;
  $st=hw_po_status_choices(); $cur=isset($_GET['hw_po_status'])?sanitize_text_field($_GET['hw_po_status']):'';
  echo '<label class="screen-reader-text" for="filter-by-hw-po-status">Filter by Status</label><select name="hw_po_status" id="filter-by-hw-po-status"><option value="">All Statuses</option>';
  foreach($st as $v=>$l){ printf('<option value="%s"%s>%s</option>',esc_attr($v),selected($cur,$v,false),esc_html($l)); } echo '</select> ';
  $nonce=wp_create_nonce('hw_po_export_csv'); $export=add_query_arg(['action'=>'hw_po_export_csv','post_type'=>'preorder','hw_po_status'=>$cur,'_wpnonce'=>$nonce],admin_url('admin-ajax.php'));
  echo '<a class="button" href="'.esc_url($export).'">Export CSV</a>';
});
add_action('parse_query',function($q){ if(!is_admin()||!$q->is_main_query())return; global $pagenow; if($pagenow!=='edit.php')return; if(!isset($_GET['post_type'])||$_GET['post_type']!=='preorder')return; if(!empty($_GET['hw_po_status'])){ $st=sanitize_text_field($_GET['hw_po_status']); $q->set('meta_query',[[ 'key'=>'hw_po_status','value'=>$st ]]); } });
add_action('wp_ajax_hw_po_export_csv',function(){
  if(!current_user_can('edit_posts') && !hw_user_can_pos_dashboard()) wp_die('Insufficient permissions',403);
  check_admin_referer('hw_po_export_csv');
  $status=isset($_GET['hw_po_status'])?sanitize_text_field($_GET['hw_po_status']):'';
  $args=['post_type'=>'preorder','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'date','order'=>'DESC']; if($status){ $args['meta_query']=[[ 'key'=>'hw_po_status','value'=>$status ]]; }
  $q=new WP_Query($args); $filename='preorders_'.($status?strtolower(str_replace(' ','_',$status)).'_':'').date('Ymd_His').'.csv';
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename='.$filename); $out=fopen('php://output','w');
  fputcsv($out,['ID','Title','Status','Priority','Assignee','Customer Name','Customer Email','Customer Phone','Customer User ID','Requested Product','Material','Color','Size','Budget','Reference Links','Reference Images','Design Notes','Estimated Quote (IDR)','Estimated Lead (days)','Request Deposit?','Deposit Amount (IDR)','Deposit Paid (IDR)','Deposit Confirmed?','Deposit Tx/Ref','Deposit Date','Created','Modified']);
  if($q->have_posts()){ while($q->have_posts()){ $q->the_post(); $pid=get_the_ID(); $assId=intval(get_post_meta($pid,'hw_assignee',true)); $ass=$assId?(get_user_by('id',$assId)->display_name??''):'';
    fputcsv($out,[$pid,get_the_title(),get_post_meta($pid,'hw_po_status',true),get_post_meta($pid,'hw_priority',true),$ass,get_post_meta($pid,'hw_cust_name',true),get_post_meta($pid,'hw_cust_email',true),get_post_meta($pid,'hw_cust_phone',true),get_post_meta($pid,'hw_customer_user_id',true),get_post_meta($pid,'hw_req_product',true),get_post_meta($pid,'hw_req_material',true),get_post_meta($pid,'hw_req_color',true),get_post_meta($pid,'hw_req_size',true),get_post_meta($pid,'hw_req_budget',true),get_post_meta($pid,'hw_req_refs',true),get_post_meta($pid,'hw_req_images',true),get_post_meta($pid,'hw_req_notes',true),get_post_meta($pid,'hw_est_quote',true),get_post_meta($pid,'hw_est_lead',true),(get_post_meta($pid,'hw_req_deposit',true)?'Yes':'No'),get_post_meta($pid,'hw_deposit_amount',true),get_post_meta($pid,'hw_deposit_paid',true),(get_post_meta($pid,'hw_deposit_confirmed',true)?'Yes':'No'),get_post_meta($pid,'hw_deposit_txid',true),get_post_meta($pid,'hw_deposit_date',true),get_the_date('Y-m-d H:i'),get_the_modified_date('Y-m-d H:i')]); }
    wp_reset_postdata();
  }
  fclose($out); exit;
});

/** =========================
 *  META BOX: Status History (admin)
 *  ========================= */
add_action('add_meta_boxes',function(){
  add_meta_box('hw_po_history','Status History',function($post){
    if($post->post_type!=='preorder')return;
    $raw=get_post_meta($post->ID,'hw_status_history',true); $hist=$raw?json_decode($raw,true):[];
    echo '<style>.hw-hist{max-height:260px;overflow:auto;border:1px solid #e5e5e5;padding:8px;background:#fff}.hw-hist table{width:100%;border-collapse:collapse}.hw-hist th,.hw-hist td{padding:6px;border-bottom:1px solid #f0f0f0;text-align:left;font-size:12px}</style>';
    echo '<div class="hw-hist"><table><thead><tr><th>Time (UTC)</th><th>User</th><th>From</th><th>To</th><th>Reason</th></tr></thead><tbody>';
    if(is_array($hist) && count($hist)){ $hist=array_reverse($hist); foreach($hist as $h){ printf('<tr><td>%s</td><td>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td></tr>',esc_html($h['ts']??'-'),esc_html($h['user_name']??'system'),esc_html($h['from']??'-'),esc_html($h['to']??'-'),esc_html($h['reason']??'')); } }
    else { echo '<tr><td colspan="5">No history yet.</td></tr>'; }
    echo '</tbody></table></div>';
  },'preorder','side','default');
});



/* =========================
 * POS Dashboard front-end: [hw_po_pos] — modern UI (FINAL)
 * ========================= */
add_shortcode('hw_po_pos', function () {
  if (!hw_user_can_pos_dashboard()) return '<p>Forbidden.</p>';

  $notice = '';

  /* ---- Quick Action: status on list ---- */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hw_po_quick_action'])) {
    if (!wp_verify_nonce($_POST['hw_po_pos_nonce'] ?? '', 'hw_po_pos_action')) {
      $notice = '<div class="hwpos-alert hwpos-alert--error">Nonce invalid.</div>';
    } else {
      $pid = intval($_POST['post_id'] ?? 0);
      $new = sanitize_text_field($_POST['qa_status'] ?? '');
      $choices = hw_po_status_choices();
      if ($pid && get_post_type($pid)==='preorder' && isset($choices[$new])) {
        hw_po_change_status($pid, $new, 'Quick Action');
        $notice = '<div class="hwpos-alert hwpos-alert--ok">Status updated.</div>';
      }
    }
  }

  /* ---- Edit Save ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hw_po_pos_update'])) {
      if (!wp_verify_nonce($_POST['hw_po_pos_nonce'] ?? '', 'hw_po_pos_action')) {
        $notice = '<div class="hwpos-alert hwpos-alert--error">Nonce invalid.</div>';
      } else {
        $pid = intval($_POST['post_id'] ?? 0);
        if ($pid && get_post_type($pid) === 'preorder') {
    
          // === Ambil & parse angka (terima 10.000.000 / IDR 10.000.000) ===
          $quote_raw = $_POST['hw_est_quote'] ?? '';
          $dep_raw   = $_POST['hw_deposit_amount'] ?? '';
          $paid_raw  = $_POST['hw_deposit_paid'] ?? '';
          $lead_raw  = $_POST['hw_est_lead'] ?? '';
          
          $quote = hw_po_parse_idr($quote_raw);
          $dep   = hw_po_parse_idr($dep_raw);
          $paid  = hw_po_parse_idr($paid_raw);
          $lead  = hw_po_parse_idr($lead_raw);
          
          // BATAS MAKS Product Quote
          if ($quote > 300000000) {
              $quote = 300000000;
              $notice .= '<div class="hwpos-alert hwpos-alert--error">Product Quote dibatasi ke maks 300.000.000.</div>';
              
          }


    
          // === Simpan field non-angka dulu ===
          $fields_other = [
            'hw_po_status','hw_priority',
            'hw_req_deposit','hw_deposit_confirmed',
            'hw_deposit_txid','hw_deposit_date','hw_internal_notes'
          ];
          foreach ($fields_other as $f) {
            $v = $_POST[$f] ?? null;
            if (in_array($f, ['hw_req_deposit','hw_deposit_confirmed'], true)) {
              update_post_meta($pid, $f, $v ? 1 : 0);
            } else {
              update_post_meta($pid, $f, is_string($v) ? wp_kses_post($v) : '');
            }
          }
    
          // === Aturan deposit: min 50% dari quote, max = quote ===
          $req_deposit = !empty($_POST['hw_req_deposit']);
          if ($quote > 0) {
            $min = floor($quote * 0.5);
            $max = $quote;
    
            // auto-suggest ke 50% jika kosong
            if ($req_deposit && $dep <= 0) $dep = $min;
    
            // paksa ke rentang valid
            if ($req_deposit && $dep < $min) {
              $dep = $min;
              $notice .= '<div class="hwpos-alert hwpos-alert--error">Deposit dinaikkan ke minimal 50%.</div>';
            }
            if ($req_deposit && $dep > $max) {
              $dep = $max;
              $notice .= '<div class="hwpos-alert hwpos-alert--error">Deposit diturunkan ke maksimal Product Quote.</div>';
            }
          } else {
            // tanpa quote, nolkan angka biar tidak menyesatkan
            $dep = 0;
          }
    
          // === Simpan angka bersih (integer) ===
          update_post_meta($pid, 'hw_est_quote',        $quote ? (int)$quote : '');
          update_post_meta($pid, 'hw_deposit_amount',   $dep   ? (int)$dep   : '');
          update_post_meta($pid, 'hw_deposit_paid',     $paid  ? (int)$paid  : '');
          update_post_meta($pid, 'hw_est_lead',         $lead  ? (int)$lead  : '');
    
          // assignee = user saat ini
          update_post_meta($pid, 'hw_assignee', get_current_user_id());
    
          // Transition status
          $new = sanitize_text_field($_POST['hw_po_status'] ?? '');
          $old = get_post_meta($pid, 'hw_po_status', true) ?: 'New';
          if ($new && $new !== $old && !hw_po_is_final_status($old)) {
            hw_po_change_status($pid, $new, 'POS page update');
          }
    
          $notice .= '<div class="hwpos-alert hwpos-alert--ok">Saved.</div>';
        } else {
          $notice = '<div class="hwpos-alert hwpos-alert--error">Invalid ticket.</div>';
        }
      }
    }


  /* ---- Filters & query ---- */
  $filter  = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
  $search  = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
  $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;

    $args = [
      'post_type'=>'preorder','post_status'=>'any','posts_per_page'=>50,
      'orderby'=>'date','order'=>'DESC',
      'no_found_rows'          => true,   // ⟵ matikan COUNT(*) total rows
      'update_post_term_cache' => false,  // ⟵ tidak pakai taxonomy
      'update_post_meta_cache' => true,   // ⟵ kita butuh meta di tabel ini
      'ignore_sticky_posts'    => true,
    ];

  $mq = [];
  if ($filter) $mq[] = ['key'=>'hw_po_status','value'=>$filter];
  if ($search) {
    $mq[] = ['relation'=>'OR',
      ['key'=>'hw_cust_name','value'=>$search,'compare'=>'LIKE'],
      ['key'=>'hw_cust_email','value'=>$search,'compare'=>'LIKE'],
      ['key'=>'hw_req_product','value'=>$search,'compare'=>'LIKE'],
    ];
  }
  if ($mq) $args['meta_query'] = $mq;

  $q = new WP_Query($args);

  $status_choices = hw_po_status_choices();
    // ADD ↓ (replace entire $status_colors)
    $status_colors = [
      'New'                => 'slate',
      'Under Review'       => 'blue',
      'Quoted'             => 'indigo',
      'Maison Preparation' => 'amber',
      'Production'         => 'purple',
      'Ready to Ship'      => 'emerald',
      'On the Way Home'    => 'teal',
      'Arrived'            => 'green',
      'Rejected'           => 'rose',
    ];
    // ADD ↑

  $pill = function($s) use ($status_colors) {
    $c=$status_colors[$s]??'slate';
    return '<span class="hwpos-pill hwpos-pill--'.$c.'" data-status="'.esc_attr($s).'">'.esc_html($s).'</span>';
  };

  ob_start(); ?>
  <style>
    .hw-pos{--accent:#3C6E71;--brand:#ED1B76;--ink:#0f172a}
    .hw-pos *{box-sizing:border-box}
    /* Hint Min/Max untuk Deposit */
    .hwpos-hint{display:block;margin-top:6px;font-size:12px;color:#64748b}
    .hwpos-hint .text-min{color:#16a34a;font-weight:700} /* hijau */
    .hwpos-hint .text-max{color:#ef4444;font-weight:700} /* merah */

    .hw-pos .card{border:1px solid #eef0f4;background:#fff;border-radius:20px;padding:16px 18px;box-shadow:0 8px 28px rgba(17,24,39,.04);margin:10px 0}

    /* Alerts */
    .hwpos-alert{padding:.65rem .8rem;border-radius:14px;font-weight:600}
    .hwpos-alert--ok{background:#ecfff5;border:1px solid #b6ffd2;color:#065f46}
    .hwpos-alert--error{background:#fff7f7;border:1px solid #fecaca;color:#991b1b}

    /* Buttons — unified look */
    .hwpos-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:14px !important;font-weight:800;border:1px solid transparent;cursor:pointer;text-decoration:none;transition:transform .15s,box-shadow .15s}
    .hwpos-btn--sm{padding:9px 12px;border-radius:14px !important;font-weight:800}
    .hwpos-btn:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(0,0,0,.08)}
    .hwpos-btn--primary{background:var(--brand);color:#fff}
    .hwpos-btn--secondary{background:#fff;border-color:rgba(60,110,113,.28);color:#0f172a}
    .hwpos-btn--ghost{background:transparent;border-color:#e2e8f0;color:#334155}
    .hwpos-btn--success{background:var(--accent);color:#fff}

    /* Inputs */
    .hwpos-input,.hwpos-select,.hwpos-textarea{width:100%;padding:.65rem .8rem;border:1px solid #e5e7eb;border-radius:14px;background:#fff}
    .hwpos-textarea{min-height:100px}

    /* Filter bar */
    .hwpos-filters .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px}
    .hwpos-filters .col-3{grid-column:span 3}.hwpos-filters .col-4{grid-column:span 4}.hwpos-filters .col-12{grid-column:span 12}
    @media(max-width:960px){.hwpos-filters .col-3,.hwpos-filters .col-4{grid-column:span 12}}

    /* Table */
    .hwpos-table-wrap{overflow:auto;-webkit-overflow-scrolling:touch}
    .hwpos-table{width:100%;border-collapse:collapse;min-width:880px;table-layout:fixed}
    .hwpos-table th,.hwpos-table td{padding:12px 14px;border-bottom:1px solid #eef2f7;text-align:left;vertical-align:top}
    .hwpos-table thead th{position:sticky;top:0;background:#fff;z-index:1;box-shadow:inset 0 -1px 0 #eef2f7}
    .hwpos-table .col-id{width:72px}.hwpos-table .col-status{width:150px}.hwpos-table .col-date{width:160px}.hwpos-table .col-actions{width:300px}

    .hwpos-pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:800}
    .hwpos-pill--slate{background:#f1f5f9;color:#0f172a}.hwpos-pill--blue{background:#e8f1ff;color:#1d4ed8}
    .hwpos-pill--indigo{background:#ede9fe;color:#4338ca}.hwpos-pill--amber{background:#fff7e6;color:#b45309}
    .hwpos-pill--purple{background:#f5e9ff;color:#7e22ce}.hwpos-pill--teal{background:#e6fffb;color:#0f766e}
    .hwpos-pill--emerald{background:#e8fff3;color:#047857}.hwpos-pill--green{background:#eaffea;color:#166534}.hwpos-pill--rose{background:#ffe9ee;color:#be123c}


    /* Edit layout: sticky left, scrollable right (desktop) */
    .hwpos-edit .grid{display:grid;grid-template-columns:1.1fr 1.2fr;gap:14px;align-items:start}
    .hwpos-edit .left-sticky{position:sticky;top:12px;align-self:start}
    .hwpos-edit .right-scroll{position:sticky;top:12px;max-height:calc(100svh - 120px);overflow:auto;-webkit-overflow-scrolling:touch}
    .hwpos-chip{display:inline-flex;align-items:center;padding:10px 14px;border-radius:14px;background:#f1f5f9;font-weight:800}

    /* FF entry thumbnails smaller */
    .hwpos-ff .hwv-kv{width:100%}
    .hwpos-ff .hwv-kv td{padding:7px 8px;border-bottom:1px solid #f3f4f6;vertical-align:top}
    .hwpos-ff .hwv-imgs{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px}
    .hwpos-ff .hwv-imgs img{width:100%;height:72px;object-fit:cover;border-radius:10px;border:1px solid #eee}

    /* Mobile/Tablet: stack + modal */
    .hwpos-ff-open{display:none}
    @media(max-width:1024px){
      .hwpos-edit .grid{grid-template-columns:1fr}
      .hwpos-edit .right-scroll{display:none}
      .hwpos-ff-open{display:inline-flex}
    }
    /* Modal for FF on mobile */
    .hwpos-modal{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:6svh 12px;background:rgba(15,23,42,.32);backdrop-filter:blur(8px)}
    .hwpos-modal.is-open{display:flex}
    .hwpos-modal .inner{width:clamp(320px,92vw,820px);max-height:min(80svh,820px);overflow:auto;background:#fff;border-radius:18px;box-shadow:0 28px 90px rgba(0,0,0,.28);padding:14px}
    .hwpos-modal .close{display:flex;justify-content:flex-end;margin-bottom:8px}
    .hwpos-modal .close .btn{display:grid;place-items:center;width:36px;height:36px;border-radius:999px;background:#0f172a;color:#fff;border:0;cursor:pointer}
  </style>

  <div class="hw-pos">
    <h2>Pre-Order POS Dashboard</h2>
    <?php echo $notice; ?>

    <!-- Filter Bar -->
    <section class="card hwpos-filters">
      <form method="get">
        <div class="grid">
          <div class="col-3">
            <label>Status</label>
            <select class="hwpos-select" name="status">
              <option value="">All</option>
              <?php foreach ($status_choices as $v=>$l): ?>
                <option value="<?php echo esc_attr($v); ?>" <?php selected($filter,$v); ?>><?php echo esc_html($l); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-4">
            <label>Cari</label>
            <input class="hwpos-input" type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Customer / Product / Email">
          </div>
          <div class="col-12">
            <button class="hwpos-btn hwpos-btn--primary" type="submit">Filter</button>
            <a class="hwpos-btn hwpos-btn--ghost" href="/po-pos/">Reset</a>
            <?php $nonce = wp_create_nonce('hw_po_export_csv');
              $export = add_query_arg(['action'=>'hw_po_export_csv','post_type'=>'preorder','hw_po_status'=>$filter,'_wpnonce'=>$nonce], admin_url('admin-ajax.php')); ?>
            <a class="hwpos-btn hwpos-btn--secondary" href="<?php echo esc_url($export); ?>">Export CSV</a>
            <?php
            $ff_entries = admin_url(
              'admin.php?page=fluent_forms&route=entries&form_id=' . intval(HW_PO_FLUENT_FORM_ID)
            );
            ?>
            <a class="hwpos-btn hwpos-btn--secondary" href="<?php echo esc_url($ff_entries); ?>">
              Open FF
            </a>

          </div>
        </div>
      </form>
    </section>

    <?php if ($edit_id):
      $p = get_post($edit_id);
      if (!$p || $p->post_type!=='preorder'): ?>
        <section class="card"><p>No ticket found.</p></section>
      <?php else:
        $m = function($k) use ($edit_id){ return get_post_meta($edit_id,$k,true); };
        $entry_id = intval($m('hw_form_entry_id'));
        $you = wp_get_current_user();
        $ff_view_url = $entry_id
          ? admin_url('admin.php?page=fluent_forms&route=entries&form_id=' . intval(HW_PO_FLUENT_FORM_ID) . '&subview=single_entry&entry_id=' . $entry_id)
          : '';
        $ff_edit_url = $entry_id
          ? $ff_view_url . '#/entries/' . $entry_id . '?sort_by=DESC&current_page=1&pos=0&type='
          : '';
      ?>
      <!-- Edit Panel -->
      <section class="card hwpos-edit">
        <div class="grid">
          <!-- Left: sticky edit -->
          <div class="card left-sticky" style="margin:0">
            <h3 style="margin-top:0">Edit <?php echo hwpo_ticket_label($edit_id); ?></h3>
            <!-- Billing Address button (di bawah title, di atas Status) -->
            <div class="hwpo-billing-wrap">
              <button id="hwpo-open-billing" type="button" class="hwpo-billing-tile">
                <span class="tile-icon" aria-hidden="true">
                  <!-- ikon kartu -->
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em">
                    <path d="M3 7a2 2 0 012-2h14a2 2 0 012 2v2H3V7zm0 4h18v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6zm4 3a1 1 0 100 2h6a1 1 0 100-2H7z"/>
                  </svg>
                </span>
                <span class="tile-text">Billing Address</span>
              </button>
            </div>


            <form method="post">
              <?php wp_nonce_field('hw_po_pos_action','hw_po_pos_nonce'); ?>
              <input type="hidden" name="hw_po_pos_update" value="1">
              <input type="hidden" name="post_id" value="<?php echo esc_attr($edit_id); ?>">

              <div class="hwpos-field">
                <label>Status</label>
                <select class="hwpos-select" name="hw_po_status">
                  <?php foreach ($status_choices as $v=>$l): ?>
                    <option value="<?php echo esc_attr($v); ?>" <?php selected($m('hw_po_status')?:'New',$v); ?>><?php echo esc_html($l); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="hwpos-field">
                <label>Priority</label>
                <select class="hwpos-select" name="hw_priority">
                  <?php foreach(['Normal','High','Urgent'] as $p2): ?>
                    <option value="<?php echo esc_attr($p2); ?>" <?php selected($m('hw_priority')?:'Normal',$p2); ?>><?php echo esc_html($p2); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="hwpos-field">
                <label>Assignee</label>
                <div class="hwpos-chip">You — <?php echo esc_html($you->display_name ?: 'Current User'); ?></div>
                <input type="hidden" name="hw_assignee" value="<?php echo esc_attr(get_current_user_id()); ?>">
              </div>

              <div class="hwpos-field">
                <label>Product Quote (IDR)</label>
                <input
                  class="hwpos-input hw-idr"
                  type="text"
                  name="hw_est_quote"
                  inputmode="numeric"
                  pattern="[0-9.,]*"
                  data-max="300000000"
                  value="<?php echo esc_attr( number_format((float) preg_replace('/[^\d]/','',$m('hw_est_quote')), 0, ',', '.') ); ?>"
                >
              </div>

              <div class="hwpos-field">
                <label>Estimated Lead (days)</label>
                <input class="hwpos-input" type="number" name="hw_est_lead" value="<?php echo esc_attr($m('hw_est_lead')); ?>">
              </div>

              <label class="hwpos-btn hwpos-btn--ghost" style="gap:10px;margin:8px 0 0 0">
                <input type="checkbox" name="hw_req_deposit" value="1" <?php checked($m('hw_req_deposit')); ?>>
                Request Deposit?
              </label>

              <div class="hwpos-field">
                <label>Deposit Amount (IDR)</label>
                <input
                  class="hwpos-input hw-idr"
                  type="text"
                  name="hw_deposit_amount"
                  inputmode="numeric"
                  pattern="[0-9.,]*"
                  value="<?php echo esc_attr( number_format((float) preg_replace('/[^\d]','', $m('hw_deposit_amount')), 0, ',', '.') ); ?>"
                >
              </div>

              <div class="hwpos-field">
                <label>Deposit Paid (IDR)</label>
                <input class="hwpos-input" type="number" name="hw_deposit_paid" value="<?php echo esc_attr($m('hw_deposit_paid')); ?>">
              </div>

              <label class="hwpos-btn hwpos-btn--ghost" style="gap:10px;margin:8px 0 0 0">
                <input type="checkbox" name="hw_deposit_confirmed" value="1" <?php checked($m('hw_deposit_confirmed')); ?>>
                Deposit Confirmed?
              </label>

              <div class="hwpos-field">
                <label>Deposit Tx/Ref</label>
                <input class="hwpos-input" type="text" name="hw_deposit_txid" value="<?php echo esc_attr($m('hw_deposit_txid')); ?>">
              </div>

              <div class="hwpos-field">
                <label>Deposit Date</label>
                <input class="hwpos-input" type="date" name="hw_deposit_date" value="<?php echo esc_attr($m('hw_deposit_date')); ?>">
              </div>

              <div class="hwpos-field">
                <label>Internal Notes</label>
                <textarea class="hwpos-textarea" name="hw_internal_notes" rows="4"><?php echo esc_textarea($m('hw_internal_notes')); ?></textarea>
              </div>

              <div style="display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:10px">
                <button class="hwpos-btn hwpos-btn--success hwpos-ff-open" type="button" data-open-ff>View Entry Details</button>
                <div style="display:flex;gap:10px">
                  <a class="hwpos-btn hwpos-btn--ghost" href="/po-pos/">Back</a>
                  <button class="hwpos-btn hwpos-btn--primary" type="submit">Save</button>
                </div>
              </div>
            </form>
          </div>

          <!-- Right: FF Entry (scrollable on desktop, hidden on mobile) -->
          <div class="card right-scroll hwpos-ff" id="hwpos-ff" style="margin:0">
            <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;flex-wrap:wrap">
              <h4 style="margin:0">Fluent Form Entry</h4>
              <?php if ($entry_id): ?>
                <div style="display:flex;gap:8px">
                  <a class="hwpos-btn hwpos-btn--secondary hwpos-btn--sm" target="_blank" rel="noopener"
                     href="<?php echo esc_url($ff_view_url); ?>">
                     Open in Fluent Forms
                  </a>
                  <a class="hwpos-btn hwpos-btn--ghost hwpos-btn--sm" target="_blank" rel="noopener"
                     href="<?php echo esc_url($ff_edit_url); ?>">
                     Edit Form
                  </a>
                </div>
              <?php endif; ?>
            </div>
            <div class="ff-content" style="margin-top:8px">
              <?php echo $entry_id ? hw_po_render_ff_entry_html($entry_id) : '<p>Belum ada data Fluent Forms.</p>'; ?>
            </div>
          </div>
        </div>
        <script>

        (function(){
          var nf = new Intl.NumberFormat('id-ID');
        
          function toNum(raw){
            if (typeof raw !== 'string') raw = (raw==null?'':String(raw));
            return parseInt(raw.replace(/[^\d]/g,'')) || 0;
          }
          function fmt(el){
            var curPos = el.selectionStart || 0;
            var beforeLen = el.value.length;
            var n = toNum(el.value);
            el.value = n ? nf.format(n) : '';
            try{
              var afterLen = el.value.length;
              var delta = afterLen - beforeLen;
              el.setSelectionRange(Math.max(0,(curPos+delta)), Math.max(0,(curPos+delta)));
            }catch(e){}
          }
          
          var q = document.querySelector('input[name="hw_est_quote"]');      // TEXT
          var d = document.querySelector('input[name="hw_deposit_amount"]'); // sekarang TEXT
          var req = document.querySelector('input[name="hw_req_deposit"]');
        
          function applyDepositConstraints(){
            var quote = toNum(q && q.value || '0');
            var min = Math.floor(quote * 0.5);
            var max = quote;
        
            // hint min/max DI BAWAH field Deposit
            var hint = d && d.parentElement.querySelector('.hwpos-hint');
            if (!hint && d) {
              hint = document.createElement('div');
              hint.className = 'hwpos-hint';
              d.parentElement.appendChild(hint);
            }
            if (hint) {
              hint.innerHTML = quote
                ? 'Min: <strong class="text-min">' + nf.format(min) +
                  '</strong> • Max: <strong class="text-max">' + nf.format(max) + '</strong>'
                : '';
            }

            // auto 50% jika kosong / masih auto
            if (q && d) {
              var dval = toNum(d.value);
              var auto = d.getAttribute('data-auto') === '1' || dval === 0;
              if (quote && (auto || d.value === '')) {
                d.value = String(min); //
                d.setAttribute('data-auto','1');
              }
            }
        
            // custom validity
            if (d) {
              var v = toNum(d.value);
              if (!quote) { d.setCustomValidity('Please fill in the Product Quote first.'); }
              else if (v < min) { d.setCustomValidity('The deposit cannot be less than 50% ('+nf.format(min)+').'); }
              else if (v > max) { d.setCustomValidity('The deposit cannot exceed the Product Quote ('+nf.format(max)+').'); }
              else { d.setCustomValidity(''); }
            }
          }
          
          [q,d].forEach(function(el){
              if (!el) return;
              if (el.value && /\d/.test(el.value)) fmt(el);  // format awal
              el.addEventListener('input', function(){
                  if (el === d) d.removeAttribute('data-auto'); // user override
                  fmt(el);                                      // ← format ribuan
                  applyDepositConstraints();
              });
              el.addEventListener('blur', function(){ fmt(el); applyDepositConstraints(); });
              
          });

        
          if (req) req.addEventListener('change', applyDepositConstraints);
          applyDepositConstraints();
        
          // jaga saat submit
          var form = document.querySelector('form input[name="hw_po_pos_update"]')?.form;
          if (form) form.addEventListener('submit', function(e){
            applyDepositConstraints();
            if (d && !d.checkValidity()) { e.preventDefault(); d.reportValidity(); }
          });
        })();
        </script>
      </section>

      <!-- Modal container (mobile/tablet) -->
      <div class="hwpos-modal" id="ffModal" aria-hidden="true">
        <div class="inner">
          <div class="close"><button class="btn" type="button" data-close-ff>×</button></div>
          <div class="content"></div>
        </div>
      </div>

      <script>
        (function(){
          var btn = document.querySelector('[data-open-ff]');
          var modal = document.getElementById('ffModal');
          if(btn && modal){
            btn.addEventListener('click', function(){
              var src = document.querySelector('#hwpos-ff .ff-content');
              var dst = modal.querySelector('.content');
              dst.innerHTML = src ? src.innerHTML : '<p>No entry.</p>';
              modal.classList.add('is-open'); modal.setAttribute('aria-hidden','false');
              document.documentElement.style.overflow='hidden';
            });
            modal.addEventListener('click', function(e){ if(e.target===modal) closeFF(); });
            modal.querySelector('[data-close-ff]').addEventListener('click', closeFF);
            function closeFF(){ modal.classList.remove('is-open'); modal.setAttribute('aria-hidden','true'); document.documentElement.style.overflow=''; }
          }
        })();
        
          function updateRanges(){
            if(!quoteEl) return;
            var maxQuote = parseInt(quoteEl.getAttribute('data-max')||'300000000',10);
            // normalize value + clamp ke MAX QUOTE
            var q = clamp(quoteEl.value, maxQuote);
            quoteEl.value = fmtIDR(q);
        
            // set batas deposit (50% - 100% dari quote)
            var minD = Math.floor(q * 0.5);
            var maxD = q;
        
            if (depoHintMin) depoHintMin.textContent = fmtIDR(minD);
            if (depoHintMax) depoHintMax.textContent = fmtIDR(maxD);
        
            if (depoEl) {
              // pasang atribut HTML5 untuk berjaga
              depoEl.setAttribute('min', String(minD));
              depoEl.setAttribute('max', String(maxD));
              // jika ada nilai, clamp juga
              if (depoEl.value) {
                var raw = parseInt(onlyDigits(depoEl.value)||'0',10);
                if (raw < minD) raw = minD;
                if (raw > maxD) raw = maxD;
                depoEl.value = raw;
              }
            }
          }
        
          if (quoteEl) {
            // inisialisasi
            updateRanges();
            // format on input
            ['input','change','blur'].forEach(function(ev){
              quoteEl.addEventListener(ev, updateRanges);
            });
          }
        })();
        </script>
      </script>

    <?php endif; else: /* ===== LIST PAGE (no edit) ===== */ ?>

      <section class="card">
        <h3>Tickets</h3>

        <?php if(!$q->have_posts()): ?>
          <p>No Ticket Found.</p>
        <?php else: ?>
          <div class="hwpos-table-wrap">
            <table class="hwpos-table">
              <thead>
                <tr>
                  <th class="col-id">#</th>
                  <th>Customer</th>
                  <th>Requested</th>
                  <th class="col-status">Status</th>
                  <th class="col-date">Date</th>
                  <th class="col-actions">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while($q->have_posts()): $q->the_post();
                  $pid=get_the_ID();
                  $cust=get_post_meta($pid,'hw_cust_name',true);
                  $email=get_post_meta($pid,'hw_cust_email',true);
                  $title=get_the_title($pid);
                  $st=get_post_meta($pid,'hw_po_status',true)?:'New';
                  $ffid=intval(get_post_meta($pid,'hw_form_entry_id',true));
                ?>
                <tr>
                  <td class="col-id"><?php echo esc_html($pid); ?></td>
                  <td><?php echo esc_html($cust?:'—'); ?><?php if($email) echo '<br><small>'.esc_html($email).'</small>'; ?></td>
                  <?php
                  $label = function_exists('hwpo_ticket_label') ? hwpo_ticket_label($pid) : '';
                  $requested = $title ?: ($label ?: ('PO — Entry #'.$ffid));
                  ?>
                  <td><strong><?php echo esc_html($requested); ?></strong></td>

                  <td class="col-status"><?php echo $pill($st); ?></td>
                  <td class="col-date"><?php echo esc_html(get_the_date('Y-m-d H:i')); ?></td>
                  <td class="col-actions">
                    <a class="hwpos-btn hwpos-btn--sm hwpos-btn--primary" href="<?php echo esc_url(add_query_arg(['edit'=>$pid], '/po-pos/')); ?>">Edit</a>
                    <?php if($ffid): ?>
                      <?php
                        $ffv = admin_url('admin.php?page=fluent_forms&route=entries&form_id='.intval(HW_PO_FLUENT_FORM_ID).'&subview=single_entry&entry_id='.$ffid);
                        $ffe = $ffv . '#/entries/' . $ffid . '?sort_by=DESC&current_page=1&pos=0&type=';
                      ?>
                      <a class="hwpos-btn hwpos-btn--sm hwpos-btn--ghost" target="_blank" rel="noopener" href="<?php echo esc_url($ffe); ?>">Edit Form</a>
                    <?php endif; ?>

                    <!-- Quick Action -->
                    <form method="post" class="hwpos-qa" style="margin-top:6px">
                      <?php wp_nonce_field('hw_po_pos_action','hw_po_pos_nonce'); ?>
                      <input type="hidden" name="hw_po_quick_action" value="1">
                      <input type="hidden" name="post_id" value="<?php echo esc_attr($pid); ?>">
                    
                      <select class="hwpos-select"
                              name="qa_status"
                              data-current="<?php echo esc_attr($st); ?>"
                              style="width:220px;display:inline-block">
                        <?php foreach($status_choices as $v=>$l): ?>
                          <option value="<?php echo esc_attr($v); ?>" <?php selected($st,$v); ?>><?php echo esc_html($l); ?></option>
                        <?php endforeach; ?>
                      </select>
                    
                      <button class="hwpos-btn hwpos-btn--sm hwpos-btn--ghost hwpos-qa-apply"
                              type="submit"
                              style="margin-left:6px;display:none">Apply</button>
                    </form>
                    
                  </td>
                </tr>
                <?php endwhile; wp_reset_postdata(); ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <script>
        (function(){
          document.querySelectorAll('.hwpos-qa').forEach(function(form){
            var select = form.querySelector('select[name="qa_status"]');
            var btn    = form.querySelector('.hwpos-qa-apply');
            if(!select || !btn) return;
        
            function refresh(){
              var cur = select.getAttribute('data-current') || '';
              var now = select.value || '';
              btn.style.display = (now !== cur) ? 'inline-flex' : 'none';
            }
            select.addEventListener('change', refresh);
            refresh(); // init tanpa reload
          });
        })();
        </script>
      </section>

    <?php endif; ?>
  </div>
  <script>
    (function(){
      // formatter ID → hanya untuk tampilan di field TEXT
      var nf = new Intl.NumberFormat('id-ID');
    
      // helper: ambil digit saja → number
      function toNum(raw){
        if (raw == null) return 0;
        var s = String(raw).replace(/[^\d]/g,'');
        return s ? parseInt(s, 10) : 0;
      }
    
      // format ribuan hanya untuk input TEXT (Product Quote)
      function fmtText(el){
        if (!el) return;
        var pos = el.selectionStart || 0;
        var before = el.value.length;
        var n = toNum(el.value);
        el.value = n ? nf.format(n) : '';
        try{
          var after = el.value.length;
          // koreksi caret
          el.setSelectionRange(pos + (after - before), pos + (after - before));
        }catch(e){}
      }
    
      // ambil elemen
      var q   = document.querySelector('input[name="hw_est_quote"]');        // Product Quote (TEXT)
      var d   = document.querySelector('input[name="hw_deposit_amount"]');   // Deposit (NUMBER)
      var req = document.querySelector('input[name="hw_req_deposit"]');      // Checkbox Request Deposit?
    
      // buat/ambil hint min-max di bawah input deposit
      function getHintNode(){
        if (!d) return null;
        var hint = d.parentElement.querySelector('.hwpos-hint');
        if (!hint) {
          hint = document.createElement('div');
          hint.className = 'hwpos-hint';
          d.parentElement.appendChild(hint);
        }
        return hint;
      }
    
      // logika utama
      function applyDepositConstraints(){
        var quote = toNum(q && q.value || 0);
        var needDeposit = req ? req.checked : true;       // kalau tak ada checkbox, anggap perlu
    
        // hitung min / max
        var min = quote ? Math.floor(quote * 0.5) : 0;
        var max = quote;
    
        // tampilkan hint
        var hint = getHintNode();
        if (hint) {
          hint.innerHTML = quote
            ? 'Min: <strong class="text-min">' + nf.format(min) +
              '</strong> • Max: <strong class="text-max">' + nf.format(max) + '</strong>'
            : '';
        }
    
        if (!d) return;
    
        // isi default 50% kalau:
        // - ada quote
        // - deposit kosong/0 ATAU belum pernah diubah manual (flag data-auto = '1')
        // - deposit memang diminta (req checked) kalau checkbox ada
        var dval = toNum(d.value);
        var auto = (d.getAttribute('data-auto') === '1') || dval === 0 || isNaN(dval);
    
        if (quote > 0 && needDeposit && auto) {
            d.value = nf.format(min);          // ← sebelumnya String(min)
            d.setAttribute('data-auto','1');
            
        }
    
        // validasi html5 (opsional, nyaman saat submit manual)
        if (quote > 0 && needDeposit) {
          d.setAttribute('min', String(min));
          d.setAttribute('max', String(max));
          var v = toNum(d.value);
          if (v < min)       d.setCustomValidity('The deposit cannot be less than 50% ('+nf.format(min)+').');
          else if (v > max)  d.setCustomValidity('The deposit cannot exceed the Product Quote ('+nf.format(max)+').');
          else               d.setCustomValidity('');
        } else {
          d.removeAttribute('min'); d.removeAttribute('max'); d.setCustomValidity('');
        }
      }
    
      // event wiring
      if (q) {
        // format tampilan quote saat awal & setiap input/blur
        if (q.value) fmtText(q);
        q.addEventListener('input', function(){ fmtText(q); applyDepositConstraints(); });
        q.addEventListener('blur',  function(){ fmtText(q); applyDepositConstraints(); });
      }
      if (d) {
        // ketika user menyentuh deposit, dianggap override manual → lepas mode auto
        d.addEventListener('input', function(){ d.removeAttribute('data-auto'); applyDepositConstraints(); });
        d.addEventListener('blur', applyDepositConstraints);
      }
      if (req) req.addEventListener('change', applyDepositConstraints);
    
      // jalan sekali saat page load (agar ketika tiket dibuka, deposit langsung terisi 50% jika kosong)
      applyDepositConstraints();
    
      // keamanan ekstra: saat submit form POS, pastikan valid
      var submit = document.querySelector('form input[name="hw_po_pos_update"]');
      if (submit && d) {
        var form = submit.form;
        if (form) form.addEventListener('submit', function(e){
          applyDepositConstraints();
          if (!d.checkValidity()) { e.preventDefault(); d.reportValidity(); }
        });
      }
    })();
    </script>
    
    <script>
    (function(){
        var forms = document.querySelectorAll('form[action][method][data-hwpos="1"], form input[name="hw_po_pos_update"] ? document.querySelectorAll("form") : []);
        if (!forms.length) forms = document.querySelectorAll('form'); // fallback
    
        function toNum(s){
          if (s == null) return '';
          s = String(s).trim();
          // buang semua selain digit dan pemisah umum
          s = s.replace(/[^\d.,-]/g,'');
          if (s.indexOf(',') !== -1 && (s.match(/,/g)||[]).length === 1 && (s.match(/\./g)||[]).length >= 1) {
            // format Eropa: 10.000.000,50 → 10000000.50
            s = s.replace(/\./g,'').replace(',', '.');
          } else {
            // IDR tanpa desimal: buang semua non-digit
            s = s.replace(/[^\d-]/g,'');
          }
          return s;
        }
    
        function normCurrencyField(el){
          if (!el) return;
          var v = el.value || '';
          var n = toNum(v);
          // biar lolos validasi, set ke angka polos (tanpa titik/koma/IDR)
          el.value = n;
        }
    
        forms.forEach(function(f){
          // matikan validasi HTML5 supaya tidak mentok di browser
          f.setAttribute('novalidate','novalidate');
    
          f.addEventListener('submit', function(e){
            try{
              // bersihkan field IDR sebelum submit
              ['hw_est_quote','hw_deposit_amount','hw_deposit_paid'].forEach(function(name){
                var el = f.querySelector('[name="'+name+'"]');
                if (el && el.value) normCurrencyField(el);
              });
    
              // aturan: jika Request Deposit? unchecked → kosongkan deposit amount agar tidak divalidasi browser
              var req = f.querySelector('[name="hw_req_deposit"]');
              var amt = f.querySelector('[name="hw_deposit_amount"]');
              if (req && amt && !req.checked) { amt.value = ''; }
    
            }catch(err){}
          }, {capture:true});
        });
      })();
      </script>
      <script>
      (function(){
          // --- Parser IDR aman: "10.000.000" / "IDR 10.000.000" → 10000000 (integer)
          function idrToInt(s){
              if (s == null) return 0;
              s = String(s).trim();
              // buang semua selain digit/koma/titik/minus
              s = s.replace(/[^\d.,-]/g, '');
              // jika format Eropa: ada 1 koma (desimal) dan ≥1 titik (ribuan)
              if (s.indexOf(',') !== -1 && (s.match(/,/g)||[]).length === 1 && (s.match(/\./g)||[]).length >= 1) {
                  s = s.replace(/\./g, '').replace(',', '.'); // jadi "10000000.50"
                  return Math.round(parseFloat(s||'0'));
              }
              // format IDR tanpa desimal → buang non-digit
              s = s.replace(/[^\d-]/g,'');
              return parseInt(s || '0', 10);
          }
          // Hook ke form POS saja
          var form = document.querySelector('form input[name="hw_po_pos_update"]')?.form || null;
          if (!form) return;
          // Field yang dipakai
          var fQuote   = form.querySelector('[name="hw_est_quote"]');
          var fReqDep  = form.querySelector('[name="hw_req_deposit"]');
          var fDepAmt  = form.querySelector('[name="hw_deposit_amount"]');
        
          // Elemen hint (opsional, jika kamu render teks Min/Max)
          var hint = form.querySelector('.hwpos-hint');
        
          // Render ulang hint Min/Max
          function renderHint(){
            if (!hint || !fQuote) return;
            var q   = idrToInt(fQuote.value);
            var min = Math.floor(q * 0.5);
            var max = q;
            if (q > 0 && fReqDep && fReqDep.checked) {
              hint.innerHTML = 'Min: <span class="text-min">'+ formatIDR(min) + '</span> &middot; '+
                               'Max: <span class="text-max">'+ formatIDR(max) + '</span>';
              hint.style.display = '';
            } else {
              hint.style.display = 'none';
            }
          }
        
          function formatIDR(n){
            try{ return 'IDR '+(parseInt(n,10)||0).toLocaleString('id-ID'); }catch(e){ return 'IDR '+(parseInt(n,10)||0); }
          }
        
          // Validasi ringan saat submit (mengizinkan = 50%)
          form.addEventListener('submit', function(e){
            var req = !!(fReqDep && fReqDep.checked);
            if (!req) return;       // kalau tidak request deposit, skip
            if (!fQuote || !fDepAmt) return;
        
            var q   = idrToInt(fQuote.value);
            var dep = idrToInt(fDepAmt.value);
            if (q <= 0) return;
        
            var min = Math.floor(q * 0.5);
            var max = q;
        
            // Allow equality
            if (dep < min) {
              e.preventDefault();
              alert('The deposit cannot be less than 50% ('+ (min).toLocaleString('id-ID') +').');
              fDepAmt.focus();
              return false;
            }
            if (dep > max) {
              e.preventDefault();
              alert('The deposit cannot exceed the Product Quote ('+ (max).toLocaleString('id-ID') +').');
              fDepAmt.focus();
              return false;
            }
          }, {capture:true});
        
          // Update hint saat input berubah
          ['input','change','blur'].forEach(function(ev){
            [fQuote,fReqDep].forEach(function(el){
              if (!el) return;
              el.addEventListener(ev, renderHint);
            })
          });
          renderHint();
      })();
      </script>
      <script>
      (function(){
          var form = document.querySelector('form input[name="hw_po_pos_update"]')?.form || null;
          if (!form) return;
          form.setAttribute('novalidate','novalidate');
          function stripIDR(el){
              if (!el || !el.value) return;
              el.value = (el.value+'').replace(/[^\d-]/g,''); // jadi angka polos
              }
              form.addEventListener('submit', function(){
                  ['hw_est_quote','hw_deposit_amount','hw_deposit_paid'].forEach(function(n){
                      stripIDR(form.querySelector('[name="'+n+'"]'));
                  });
                  var req = form.querySelector('[name="hw_req_deposit"]');
                  var amt = form.querySelector('[name="hw_deposit_amount"]');
                  if (req && amt && !req.checked) { amt.value = ''; }
              }, {capture:true});
      })();
      </script>

      
  <?php
  return ob_get_clean();
});






/** =========================
 *  Regenerate Pages (opsional via wp-admin)
 *  ========================= */
add_action('admin_menu',function(){
  add_submenu_page('edit.php?post_type=preorder','Regenerate Pages','Regenerate Pages','manage_options','hw_po_regen_pages',function(){
    if(isset($_POST['hw_po_regen_nonce']) && wp_verify_nonce($_POST['hw_po_regen_nonce'],'hw_po_regen')){
      $pre='[hw_preorder_block]'; $p=get_page_by_path(HW_PO_BASE_SLUG); if($p){ wp_update_post(['ID'=>$p->ID,'post_content'=>$pre]); } else { wp_insert_post(['post_title'=>'Pre-Order','post_name'=>HW_PO_BASE_SLUG,'post_status'=>'publish','post_type'=>'page','post_content'=>$pre]); }
      $pos='[hw_po_pos]'; $pp=get_page_by_path('po-pos'); if($pp){ wp_update_post(['ID'=>$pp->ID,'post_content'=>$pos]); } else { wp_insert_post(['post_title'=>'PO POS','post_name'=>'po-pos','post_status'=>'publish','post_type'=>'page','post_content'=>$pos]); }
      echo '<div class="updated notice"><p>Pages regenerated.</p></div>';
    }
    echo '<div class="wrap"><h1>Regenerate Pre-Order Pages</h1><form method="post">'; wp_nonce_field('hw_po_regen','hw_po_regen_nonce'); echo '<p>Isi ulang konten <code>/'.esc_html(HW_PO_BASE_SLUG).'</code> dengan <code>[hw_preorder_block]</code> dan <code>/po-pos</code> dengan <code>[hw_po_pos]</code>.</p><p><button class="button button-primary">Regenerate Now</button></p></form></div>';
  });
});

/** =========================
 *  Safe redirect setelah social login (XS / lainnya)
 *  ========================= */
add_filter('login_redirect', function($redirect_to, $requested, $user){
  $cookie = hw_po_get_redirect_cookie();
  if ($cookie) { hw_po_clear_redirect_cookie(); return $cookie; }
  return $redirect_to;
}, 9999, 3);
add_action('init', function(){
  $cookie = hw_po_get_redirect_cookie();
  if ( is_user_logged_in() && $cookie ) {
    $scheme = is_ssl() ? 'https://' : 'http://';
    $here = $scheme . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    if ( rtrim($cookie,'/') === rtrim($here,'/') ) { hw_po_clear_redirect_cookie(); return; }
    hw_po_clear_redirect_cookie(); wp_safe_redirect($cookie); exit;
  }
});

/** =========================
 *  UI TUNE di /pre-order (Fluent Forms & grid produk)
 *  ========================= */
add_action('wp_head', function () {
  if (!is_page(HW_PO_BASE_SLUG)) return; ?>
  <style id="hw-po-ui-tune">
    #hw-po-wrap .ff-el-form{padding:0 !important;margin:0 !important}
    #hw-po-wrap .ff-el-group{margin-bottom:10px !important}
    #hw-po-wrap .ff-el-input--label{margin-bottom:6px !important}
    #hw-po-wrap .ff-el-form-control{padding:.6rem .75rem !important}
    /* === Fix tel input (intl-tel-input) === */
    #hw-po-wrap .ff-el-form .iti{ width:100%; }
    #hw-po-wrap .ff-el-form .iti .iti__flag-container{ left:12px; }
    #hw-po-wrap .ff-el-form .iti input[type="tel"],
    #hw-po-wrap .ff-el-form .iti input[type="text"]{
      padding-left:62px !important; height:48px;
    }
    #hw-po-wrap .ff-el-form .ff-tel-input,
    #hw-po-wrap .ff-el-form input[type="tel"]{ width:100% !important; }

    /* Grid produk opsional (mengikuti brief lama) */
    #hw-po-wrap ul.products{display:grid !important;grid-template-columns:repeat(4, minmax(0,1fr));gap:12px}
    #hw-po-wrap ul.products li.product{width:auto !important;margin:0 !important;float:none !important}
    @media (max-width:767.98px){ #hw-po-wrap ul.products{ grid-template-columns:repeat(2, minmax(0,1fr)); } }
  </style>
<?php });

function hw_po_normalize_status($s){
  $map = [
    'Waiting Deposit' => 'Quoted',
    'In Production'   => 'Production',
    'QC'              => 'On the Way Home',
    'Done'            => 'Arrived',
  ];
  $s = trim((string)$s);
  return $map[$s] ?? $s;
}
// Naikkan status tiket begitu pembayaran WooCommerce sukses
add_action('woocommerce_payment_complete', 'hw_po_on_wc_paid');
add_action('woocommerce_order_status_changed', function($order_id, $from, $to){
  if (in_array($to, ['processing','completed'], true)) hw_po_on_wc_paid($order_id);
}, 10, 3);

function hw_po_on_wc_paid($order_id){
  $order = wc_get_order($order_id); if(!$order) return;

  $pid  = (int) $order->get_meta('_hw_po_ticket_id');
  $kind = (string) $order->get_meta('_hw_po_payment_type'); // 'deposit' | 'full'
  if(!$pid || get_post_type($pid)!=='preorder') return;

  $paid = (float) $order->get_total();

  if ($kind === 'deposit') {
    // catat deposit & anggap confirmed jika dibayar via gateway
    update_post_meta($pid, 'hw_deposit_paid',       (int) $paid);
    update_post_meta($pid, 'hw_deposit_confirmed',  1);
    update_post_meta($pid, 'hw_deposit_txid',       (string) $order->get_transaction_id() );
    update_post_meta($pid, 'hw_deposit_date',       current_time('Y-m-d') );

    // gunakan helper yang sudah ada (akan naik ke 'Maison Preparation' bila syarat terpenuhi)
    hw_po_maybe_to_maison_preparation($pid);

  } else { // full payment
    // catat pembayaran penuh
    update_post_meta($pid, 'hw_full_payment_paid', (int) $paid);

    // untuk full payment, langsung naikkan ke Maison Preparation (tanpa perlu deposit)
    $cur = get_post_meta($pid,'hw_po_status',true) ?: 'New';
    if ($cur !== 'Maison Preparation' && !hw_po_is_final_status($cur)) {
      hw_po_change_status($pid, 'Maison Preparation', 'Full payment received');
    }
  }
}

// SET COOKIE LEBIH AWAL AGAR REDIRECT SETELAH LOGIN BERHASIL
add_action('template_redirect', function () {
  if (!is_user_logged_in() && is_page(HW_PO_BASE_SLUG)) {
    $v = get_query_var('hwpo_view');
    if (in_array($v, ['form','list'], true)) {
      if (!headers_sent()) {
        // aman untuk PHP baru (SameSite=Lax)
        if (PHP_VERSION_ID >= 70300) {
          setcookie('hw_po_redirect_to', esc_url_raw(hw_po_current_url()), [
            'expires'  => time()+900,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
          ]);
        } else {
          setcookie('hw_po_redirect_to', esc_url_raw(hw_po_current_url()), time()+900, '/', '', is_ssl(), true);
        }
      }
    }
  }
});

/* ==== Inject tombol Billing Address di /po-pos?edit=ID ==== */
add_action('wp_footer', function(){
  if( !is_page('po-pos') ) return;
  if( empty($_GET['edit']) ) return;
  if( !hw_user_can_pos_dashboard() && !hw_user_is_admin() && !hw_user_has_role('shop_manager') && !hw_user_has_role('editor') ) return;

  $pid   = intval($_GET['edit']);
  if(!$pid) return;

  $nonce = wp_create_nonce('hw_po_view');
  $ajax  = admin_url('admin-ajax.php');
  ?>
  <style>
    .hwpo-modal-mini{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.38);backdrop-filter:blur(6px)}
    .hwpo-modal-mini.is-open{display:flex}
    .hwpo-modal-mini .inner{background:#fff;border-radius:16px;box-shadow:0 30px 90px rgba(0,0,0,.28);width:min(560px,92vw);max-height:80vh;overflow:auto;padding:18px}
    .hwpo-modal-mini .head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
    .hwpo-modal-mini .close{background:#f3f4f6;border:0;border-radius:10px;padding:6px 10px;font-weight:800;cursor:pointer}
    #hwpo-open-billing { transition: opacity .15s ease, box-shadow .15s ease; }
    #hwpo-open-billing:focus { box-shadow: 0 0 0 3px rgba(60,110,113,.25); }
    #hwpo-open-billing[disabled]{ opacity:.5; cursor:not-allowed; }
    .hwpo-billing-wrap{ margin:16px 0 28px; border-radius:14px; }
    .hwpo-billing-tile{
      display:inline-flex; flex-direction:column; align-items:center; justify-content:center; width:180px;
      aspect-ratio:1/1;
      background:#3C6E71; color:#fff; border:0; border-radius:14px;
      box-shadow:0 1px 2px rgba(0,0,0,.06); cursor:pointer;
      transition:transform .06s ease, opacity .15s ease;
    }
    .hwpo-billing-tile:hover{ opacity:.95 }
    .hwpo-billing-tile:active{ transform:translateY(1px) }
    .hwpo-billing-tile:focus{ outline:2px solid #E6EEF0; outline-offset:3px; }
    .hwpo-billing-tile .tile-icon{ width:72px; height:72px; display:flex; align-items:center; justify-content:center; }
    .hwpo-billing-tile .tile-icon svg{ width:48px; height:48px }
    .hwpo-billing-tile .tile-text{ font-weight:700; letter-spacing:.2px }
  </style>

  <div class="hwpo-modal-mini" id="hwpo-billing-modal" aria-hidden="true">
    <div class="inner">
      <div class="head">
        <h3 style="margin:0">Billing Address</h3>
        <button type="button" class="close" id="hwpo-close-billing">Close</button>
      </div>
      <div id="hwpo-billing-body">Loading…</div>
    </div>
  </div>
  <script>
  (function(){
    var openBtn = document.getElementById('hwpo-open-billing');
    var modal   = document.getElementById('hwpo-billing-modal');
    var body    = document.getElementById('hwpo-billing-body');

    function openM(){ modal.classList.add('is-open'); modal.setAttribute('aria-hidden','false'); document.documentElement.style.overflow='hidden'; }
    function closeM(){ modal.classList.remove('is-open'); modal.setAttribute('aria-hidden','true'); document.documentElement.style.overflow=''; }

    openBtn.addEventListener('click', function(){
      body.textContent = 'Loading…';
      openM();
      var fd = new FormData();
      fd.append('action','hw_po_billing_snapshot');
      fd.append('id','<?php echo esc_js($pid); ?>');
      fd.append('nonce','<?php echo esc_js($nonce); ?>');
      fetch('<?php echo esc_url($ajax); ?>', { method:'POST', credentials:'same-origin', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(res){
          if(res && res.success){ body.innerHTML = res.data.html; }
          else{ body.textContent = (res && res.data && res.data.message) ? res.data.message : 'Failed'; }
        })
        .catch(function(){ body.textContent='Error'; });
    });

    document.getElementById('hwpo-close-billing').addEventListener('click', closeM);
    modal.addEventListener('click', function(e){ if(e.target===modal) closeM(); });
  })();
  </script>
  <?php
});






// ==============================
// Art visuals for /pre-order/
// ==============================
add_action('wp_head', function () {
    if (!function_exists('is_page') || !is_page('pre-order')) return;
    // CSS only (no data-URL). Safe to echo.
    echo <<<CSS
<style id="hw-preorder-art-css">
  :root{
    --hw-accent: #3C6E71;   /* teal profesional */
    --hw-accent2:#ED1B76;   /* signature pink */
  }

  /* Layer berada di belakang hero/kartu */
  .hw-preorder-art{
    position:fixed; inset:0 0 auto 0; height:62vh;
    z-index:0; pointer-events:none; overflow:hidden;
  }

  /* Soft gradients kiri/kanan/atas */
  .hw-preorder-grad{
    position:absolute; inset:-10% -10% 0 -10%;
    background:
      radial-gradient(60% 55% at 15% 25%, rgba(60,110,113,.22) 0%, rgba(60,110,113,0) 60%),
      radial-gradient(55% 50% at 85% 18%, rgba(237,27,118,.18) 0%, rgba(237,27,118,0) 60%),
      radial-gradient(70% 60% at 50% -10%, rgba(0,0,0,.05) 0%, rgba(0,0,0,0) 70%);
    filter: saturate(105%);
  }

  /* Abstract blobs (tanpa SVG/URL) */
  .hw-blob{
    position:absolute; width:520px; height:520px;
    border-radius:45% 55% 50% 50%/55% 45% 55% 45%;
    filter: blur(18px); opacity:.22;
    animation: hw-float 18s ease-in-out infinite;
  }
  .hw-blob.teal{ background: radial-gradient(circle at 30% 40%, rgba(60,110,113,.55), rgba(60,110,113,0) 60%);
                 left:-120px; top:-140px; animation-delay:.2s; }
  .hw-blob.pink{ background: radial-gradient(circle at 70% 30%, rgba(237,27,118,.45), rgba(237,27,118,0) 60%);
                 right:-140px; top:-170px; animation-delay:.8s; }
  .hw-blob.grey{ background: radial-gradient(circle at 50% 60%, rgba(16,18,20,.30), rgba(16,18,20,0) 60%);
                 left: 32%; top:-210px; animation-delay:1.2s; }

  @keyframes hw-float{
    0%{transform:translateY(0) scale(1) rotate(0deg)}
    50%{transform:translateY(-18px) scale(1.03) rotate(3deg)}
    100%{transform:translateY(0) scale(1) rotate(0deg)}
  }

  /* Doodle strokes (SVG inline, aman) */
  .hw-doodle{ position:absolute; width:480px; height:180px; opacity:.26; }
  .hw-doodle.tl{ left:6vw; top:10vh; transform:rotate(-4deg); }
  .hw-doodle.tr{ right:6vw; top:8vh;  transform:rotate(3deg);  }
  .hw-doodle svg path{ fill:none; stroke-linecap:round; stroke-width:2.5; }
  .hw-doodle .s1{ stroke: var(--hw-accent); }
  .hw-doodle .s2{ stroke: var(--hw-accent2); }
  .hw-doodle svg path{
    stroke-dasharray:320; stroke-dashoffset:320; animation: hw-draw 3.4s ease-in-out forwards;
  }
  .hw-doodle.tr svg path{ animation-delay:.5s; }

  @keyframes hw-draw{
    0%{stroke-dashoffset:320; opacity:.0}
    25%{opacity:.22}
    100%{stroke-dashoffset:0; opacity:.22}
  }

  /* Hormati preferensi aksesibilitas */
  @media (prefers-reduced-motion: reduce){
    .hw-blob, .hw-doodle svg path{ animation:none !important; }
  }

  /* Pastikan konten berada di atas */
  main, #content, .site-content, .elementor, body > div{ position:relative; z-index:1; }
</style>
CSS;
}, 20);

add_action('wp_footer', function () {
    if (!function_exists('is_page') || !is_page('pre-order')) return;
    // Markup ringan tanpa data-URL; SVG inline aman.
    ?>
    <div class="hw-preorder-art" aria-hidden="true">
      <div class="hw-preorder-grad"></div>

      <div class="hw-blob teal"></div>
      <div class="hw-blob pink"></div>
      <div class="hw-blob grey"></div>

      <div class="hw-doodle tl">
        <svg viewBox="0 0 480 180" role="img" aria-label="art strokes">
          <path class="s1" d="M10,120 C80,60 160,100 230,70 S390,35 470,80" />
          <path class="s2" d="M20,150 C110,120 200,160 260,130 S380,100 460,140" />
        </svg>
      </div>

      <div class="hw-doodle tr">
        <svg viewBox="0 0 480 180" role="img" aria-label="art strokes">
          <path class="s2" d="M15,40 C90,85 180,30 260,70 S380,110 465,60" />
          <path class="s1" d="M25,75 C120,115 210,60 270,95 S370,135 455,95" />
        </svg>
      </div>
    </div>
    <?php
}, 20);

























































/**
 * Plugin Name: HW – WC Product Reference (Pre-Order Search)
 * Description: Modal glass search untuk referensi produk: initial latest (responsif), pencarian berprioritas (Title > Category > Deskripsi), infinite scroll, dimensi dari deskripsi (nilai-5). UI minimalis (borderless). Hanya menampilkan produk IN-STOCK. Hasil diurutkan terbaru→terlama. Termasuk Search HW untuk header/navbar.
 * Version:     1.5.0
 * Author:      Hayu Widyas Dev
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
 * Assets (CSS + JS)
 * ============================================================ */
function hw_po_enqueue_ref_assets(){
  static $enqueued = false;
  if ($enqueued) return;
  if (is_admin() && !wp_doing_ajax()) return;

  $enqueued = true;
  $ver = '1.5.0';

  wp_register_style('hw-wc-ref', false, [], $ver);
  wp_enqueue_style('hw-wc-ref');

  $css = <<<CSS
/* ===== Field (di halaman) ===== */
.hw-ref-wrap{position:relative;margin:12px 0}
.hw-ref-label{display:block;margin-bottom:.5rem;font-weight:700;color:#2b2b2b}

/* Field pemicu (di halaman) dibuat borderless */
.hw-ref-input{
  display:flex;align-items:center;gap:.6rem;width:100%;
  border:0;border-radius:14px;padding:12px 0;background:transparent;
  box-shadow:none;cursor:text;
}
.hw-ref-input:hover{box-shadow:none}
.hw-ref-input .ico{opacity:.55}
.hw-ref-input input[type="text"]{
  border:0;outline:0;background:transparent;width:100%;font-size:16px;color:#333
}
.hw-ref-summary{margin-top:10px;display:flex;align-items:center;gap:12px}
.hw-ref-summary img{width:64px;height:64px;object-fit:cover;border-radius:12px;border:1px solid #eee}
.hw-ref-summary .meta{line-height:1.2}
.hw-ref-summary .meta .title{font-weight:600}
.hw-ref-summary .meta a{font-size:12px;color:#777;text-decoration:underline}
.hw-ref-clear{margin-left:auto;font-size:12px;color:#999;cursor:pointer}

/* ===== Overlay + Modal ===== */
.hw-ref-overlay{
  position:fixed;inset:0;z-index:99999;
  background:rgba(20,20,20,.22);
  backdrop-filter:saturate(140%) blur(6px);
  -webkit-backdrop-filter:saturate(140%) blur(6px);
  opacity:0;pointer-events:none;transition:opacity .28s ease;
}
.hw-ref-overlay.active{opacity:1;pointer-events:auto}
.hw-ref-modal{
  position:absolute;left:50%;top:6%;transform:translate(-50%,-16px);
  width:min(1100px,92vw);
  background:rgba(255,255,255,.975);
  backdrop-filter:blur(24px) saturate(180%); -webkit-backdrop-filter:blur(24px) saturate(180%);
  border:1px solid rgba(255,255,255,.78);
  border-radius:20px;box-shadow:0 28px 90px rgba(0,0,0,.2);
  padding:16px 14px 12px;opacity:0;
  transition:transform .32s cubic-bezier(.2,.8,.2,1), opacity .28s ease;
}
.hw-ref-overlay.active .hw-ref-modal{transform:translate(-50%,0);opacity:1}

/* ===== Header (borderless, search dominan) ===== */
.hw-ref-head{
  display:grid;align-items:center;grid-template-columns:auto 1fr auto;gap:0;
  border-bottom:1px solid rgba(0,0,0,.08);padding:6px 2px 8px
}

/* Category: teks polos + pemisah vertikal tipis */
.hw-ref-cat{position:relative;padding-right:12px}
.hw-ref-cat::after{content:"";position:absolute;right:0;top:50%;transform:translateY(-50%);width:1px;height:20px;background:rgba(0,0,0,.08)}
.hw-ref-cat select{
  appearance:none;border:0;outline:none;background:transparent;
  padding:10px 18px 10px 0;font-size:15px;color:#555;box-shadow:none
}

/* Search: benar-benar tanpa border/box */
.hw-ref-search{
  min-width:0;display:flex;align-items:center;gap:10px;
  padding:10px 0;background:transparent;border:0;box-shadow:none
}
.hw-ref-search,
.hw-ref-search:focus,
.hw-ref-search:focus-within{outline:none !important;box-shadow:none !important;border-color:transparent !important}
.hw-ref-search input{
  width:100%;border:0 !important;outline:0 !important;box-shadow:none !important;
  background:transparent;font-size:16px;color:#333
}
.hw-ref-search input::placeholder{color:#9aa0a6}
.hw-ref-search svg{opacity:.55}

/* Close: ikon polos (tanpa border) */
.hw-ref-close{
  width:36px;height:36px;border-radius:50%;
  display:grid;place-items:center;border:0;background:transparent;cursor:pointer
}

/* ===== Results ===== */
.hw-ref-results{padding:16px 2px 8px;max-height:62vh;overflow:auto}

/* Grid: mobile & tablet 2 kolom; desktop 3; large 4 */
.hw-ref-grid{display:grid;grid-template-columns:repeat(2, minmax(0,1fr));gap:12px}
@media (min-width:960px){.hw-ref-grid{grid-template-columns:repeat(3, minmax(0,1fr))}}
@media (min-width:1280px){.hw-ref-grid{grid-template-columns:repeat(4, minmax(0,1fr))}}

.hw-card{
  border:1px solid #eee;border-radius:14px;background:#fff;overflow:hidden;cursor:pointer;
  transition:transform .12s ease, box-shadow .12s ease
}
.hw-card:hover{transform:translateY(-2px);box-shadow:0 12px 32px rgba(0,0,0,.12)}
.hw-card .img{position:relative;aspect-ratio:3/2;background:#f7f7f7;display:block}
.hw-card .img img{width:100%;height:100%;object-fit:cover}
.hw-card .badge{
  position:absolute;right:8px;bottom:8px;font-size:12px;padding:6px 10px;border-radius:12px;background:rgba(255,255,255,.92);border:1px solid #eee
}
.hw-card .body{padding:9px 10px}
.hw-card .title{
  font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.4em;font-size:14px
}
.hw-card .dim{margin-top:6px;color:#666;font-size:12px}

/* Empty/Skeleton */
.hw-ref-empty{padding:22px;text-align:center;color:#666}
.hw-skel{border:1px solid #eee;border-radius:14px;overflow:hidden;background:#fff}
.hw-skel .ph{
  height:0;padding-top:66%;
  background:linear-gradient(90deg,#f2f2f2 25%,#e9e9e9 37%,#f2f2f2 63%);background-size:400% 100%;
  animation:shimmer 1.2s infinite
}
.hw-skel .tx{padding:10px}
.hw-skel .l1,.hw-skel .l2{height:10px;border-radius:6px;background:#eee}
.hw-skel .l1{width:80%;margin-bottom:8px}
.hw-skel .l2{width:50%}

/* ===== Global (navbar) search ===== */
.hw-ref-global{min-width:0}
.hw-ref-global .hw-ref-head{border-bottom:0;padding:0;gap:8px}
.hw-ref-submit{
  border:0;background:transparent;width:36px;height:36px;border-radius:50%;
  display:grid;place-items:center;cursor:pointer
}

/* Fine-tune tablet/mobile header agar search tetap dominan */
@media (max-width:959.98px){.hw-ref-cat select{max-width:180px}}
@keyframes shimmer{0%{background-position:100% 0}100%{background-position:0 0}}
CSS;
  wp_add_inline_style('hw-wc-ref', $css);

  wp_register_script('hw-wc-ref', '', [], $ver, true);
  wp_enqueue_script('hw-wc-ref');

  $ajax = admin_url('admin-ajax.php');
  $js = <<<JS
(function(){
  const DEBOUNCE = (fn, d=300)=>{let t;return (...a)=>{clearTimeout(t);t=setTimeout(()=>fn.apply(this,a),d)}};

  function cols(){ const w=window.innerWidth; if(w>=1280) return 4; if(w>=960) return 3; return 2; }
  function rows(){ return (window.innerWidth<640)?2:4; }
  function initialCountDynamic(){ return cols()*rows(); }

  function initOne(root){
    const input = root.querySelector('input[data-role="display"]');
    const openBtn = root.querySelector('.hw-ref-input');
    const overlay = root.querySelector('.hw-ref-overlay');
    const close = root.querySelector('.hw-ref-close');
    const q = root.querySelector('input[data-role="q"]');
    const catSel = root.querySelector('select[data-role="cat"]');
    const results = root.querySelector('.hw-ref-results');
    const perPage = parseInt(root.dataset.perPage || '10',10);
    const min = parseInt(root.dataset.min || '3',10);
    const initialAttr = parseInt(root.dataset.initial || '8',10);
    const initialMode = (root.dataset.initialMode || 'dynamic');

    const hTitle = root.querySelector('input[type="hidden"][data-field="title"]');
    const hLink  = root.querySelector('input[type="hidden"][data-field="link"]');
    const hImg   = root.querySelector('input[type="hidden"][data-field="image"]');
    const hDim   = root.querySelector('input[type="hidden"][data-field="dim"]');
    const hID    = root.querySelector('input[type="hidden"][data-field="id"]');
    const sumEl  = root.querySelector('.hw-ref-summary');

    let mode = 'latest';
    let page = 1;
    let hasMore = true;
    let isLoading = false;
    let lastQuery = '';

    function getInitialCount(){ return (initialMode==='fixed') ? initialAttr : initialCountDynamic(); }

    function open(){
      overlay.classList.add('active');
      q.value=''; q.focus(); q.select();
      mode='latest'; page=1; hasMore=true; lastQuery='';
      fetchLatest(getInitialCount(), true);
    }
    function closeModal(){ overlay.classList.remove('active'); }

    openBtn.addEventListener('click', open);
    input.addEventListener('focus', open);
    close.addEventListener('click', closeModal);
    overlay.addEventListener('click', (e)=>{ if(e.target===overlay) closeModal(); });

    window.addEventListener('resize', DEBOUNCE(()=>{
      if(!overlay.classList.contains('active')) return;
      if(mode==='latest') fetchLatest(getInitialCount(), true);
    }, 200));

    function renderCards(items, append=false){
      const grid = append ? results.querySelector('.hw-ref-grid') : document.createElement('div');
      if(!append){ grid.className='hw-ref-grid'; }
      items.forEach(o=>{
        const card = document.createElement('div');
        card.className='hw-card';
        card.innerHTML = `
          <div class="img"><img src="\${o.image}" alt=""><div class="badge">\${o.price_html || ''}</div></div>
          <div class="body">
            <div class="title">\${o.title}</div>
            <div class="dim">\${o.dimensions || ''}</div>
          </div>`;
        card.addEventListener('click', ()=>{
          input.value = o.title;
          hTitle.value = o.title;
          hLink.value  = o.link;
          hImg.value   = o.image;
          hDim.value   = o.dimensions || '';
          hID.value    = o.id;
          sumEl.innerHTML = `
            <img src="\${o.image}" alt="">
            <div class="meta">
              <div class="title">\${o.title}</div>
              <div class="dim">\${o.dimensions || ''}</div>
              <a href="\${o.link}" target="_blank" rel="noopener">View product</a>
            </div>
            <span class="hw-ref-clear">Clear</span>`;
          sumEl.querySelector('.hw-ref-clear')?.addEventListener('click', ()=>{
            input.value=''; [hTitle,hLink,hImg,hDim,hID].forEach(x=>x.value=''); sumEl.innerHTML='';
          });
          closeModal();
        });
        grid.appendChild(card);
      });
      if(!append){ results.innerHTML=''; results.appendChild(grid); }
    }

    function renderEmpty(){ results.innerHTML = '<div class="hw-ref-empty">No results found.</div>'; }

    function skeleton(n){
      const grid = results.querySelector('.hw-ref-grid') || document.createElement('div');
      if(!grid.classList.contains('hw-ref-grid')){ grid.className='hw-ref-grid'; results.innerHTML=''; results.appendChild(grid); }
      for(let i=0;i<n;i++){
        const s = document.createElement('div');
        s.className='hw-skel';
        s.innerHTML = '<div class="ph"></div><div class="tx"><div class="l1"></div><div class="l2"></div></div>';
        grid.appendChild(s);
      }
      return grid;
    }

    async function fetchLatest(count){
      isLoading=true;
      try{
        const form = new FormData();
        form.append('action','hw_wc_ref_search');
        form.append('latest','1');
        form.append('per_page', (count||8).toString());
        form.append('cat', catSel.value || '');
        const r = await fetch('{$ajax}', { method:'POST', body: form, credentials:'same-origin' });
        const j = await r.json();
        if(j && j.success){ renderCards(j.data, false); hasMore=false; }
        else { renderEmpty(); }
      }catch(e){ renderEmpty(); }
      isLoading=false;
    }

    async function fetchSearch(pageToLoad, append=false){
      isLoading=true;
      try{
        const form = new FormData();
        form.append('action','hw_wc_ref_search');
        form.append('q', lastQuery);
        form.append('cat', catSel.value || '');
        form.append('per_page', perPage.toString());
        form.append('page', pageToLoad.toString());
        const r = await fetch('{$ajax}', { method:'POST', body: form, credentials:'same-origin' });
        const j = await r.json();
        if(j && j.success){
          if(!append) results.innerHTML = '';
          renderCards(j.data.items || j.data, append);
          hasMore = !!(j.data && j.data.has_more);
        } else {
          if(!append) renderEmpty();
          hasMore = false;
        }
      }catch(e){
        if(!append) renderEmpty();
        hasMore=false;
      }
      isLoading=false;
    }

    function handleInput(){
      const s = q.value.trim();
      if(s.length < min){
        mode='latest'; page=1; hasMore=false;
        fetchLatest(getInitialCount(), true);
        return;
      }
      mode='search'; page=1; hasMore=true; lastQuery=s;
      results.innerHTML=''; skeleton(Math.min(initialCountDynamic(), perPage));
      fetchSearch(page, false);
    }

    q.addEventListener('input', DEBOUNCE(handleInput, 300));
    catSel.addEventListener('change', ()=>{
      if((q.value||'').trim().length >= min){ mode='search'; page=1; hasMore=true; lastQuery=q.value.trim(); fetchSearch(page,false); }
      else { mode='latest'; fetchLatest(getInitialCount(), true); }
    });

    results.addEventListener('scroll', async ()=>{
      if(mode!=='search' || !hasMore || isLoading) return;
      const {scrollTop, scrollHeight, clientHeight} = results;
      if(scrollTop + clientHeight >= scrollHeight - 60){
        isLoading=true;
        page += 1;
        skeleton(4);
        await fetchSearch(page, true);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    document.querySelectorAll('.hw-ref-root').forEach(initOne);
  });
})();
JS;
  wp_add_inline_script('hw-wc-ref', $js);
}

/* ============================================================
 * Helpers
 * ============================================================ */
function hw_ref_norm($s){
  $s = remove_accents(strtolower($s));
  return preg_replace('/[^a-z0-9]+/','', $s);
}
function hw_ref_match_cat_term_ids($query){
  $q = hw_ref_norm($query);
  if(!$q) return [];
  static $cache = null;
  if ($cache === null) {
    $terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'number'=>0]);
    if(is_wp_error($terms) || !$terms){
      $cache = [];
    } else {
      $cache = array_map(function($t){
        return [
          'id'   => (int)$t->term_id,
          'name' => hw_ref_norm($t->name),
          'slug' => hw_ref_norm($t->slug),
        ];
      }, $terms);
    }
  }

  if (!$cache) return [];

  $ids = [];
  foreach($cache as $t){
    $n1 = $t['name'];
    $n2 = $t['slug'];
    if($n1==='' && $n2==='') continue;
    if(strpos($n1,$q)!==false || strpos($n2,$q)!==false || strpos($q,$n1)!==false){
      $ids[] = $t['id'];
    }
  }
  return array_values(array_unique($ids));
}

/* ===== ambil dimensi dari deskripsi (nilai-5) ===== */
function hw_ref_extract_dimensions_from_content($content) {
  if (!$content) return '';
  $pattern = '/size\\s*[:\\-]?\\s*([0-9]+(?:\\.[0-9]+)?(?:\\s*(?:x|×)\\s*[0-9]+(?:\\.[0-9]+)?(?:\\s*(?:x|×)\\s*[0-9]+(?:\\.[0-9]+)?)?)?)\\s*cm\\b/i';
  if (!preg_match($pattern, $content, $m)) {
    $pattern2 = '/size\\s*[:\\-]?\\s*([0-9]+(?:\\.[0-9]+)?)\\s*cm\\b/i';
    if (!preg_match($pattern2, $content, $m)) return '';
  }
  $raw = trim($m[1]);

  $parts = preg_split('/\\s*(?:x|×)\\s*/i', $raw);
  $outNums = [];
  foreach ($parts as $p) {
    $n = floatval(str_replace(',', '.', $p));
    $n = max(0, $n - 5);
    $fmt = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    $outNums[] = $fmt;
  }
  $unit = get_option('woocommerce_dimension_unit', 'cm') ?: 'cm';
  return (count($outNums) === 1) ? ($outNums[0] . ' ' . $unit) : (implode('×', $outNums) . ' ' . $unit);
}

// Sinkron status tiket setelah pembayaran selesai di WooCommerce
add_action('woocommerce_payment_complete', 'hw_po_sync_paid_order');
add_action('woocommerce_order_status_processing', 'hw_po_sync_paid_order');
add_action('woocommerce_order_status_completed', 'hw_po_sync_paid_order');

function hw_po_sync_paid_order($order_id){
  if (!$order_id) return;
  $order = wc_get_order($order_id);
  if (!$order) return;

  $pid  = intval($order->get_meta('_hw_po_ticket_id'));
  $type = (string) $order->get_meta('_hw_po_payment_type'); // 'deposit' | 'full'
  if (!$pid || get_post_type($pid)!=='preorder') return;

  $total = (float) $order->get_total();

  if ($type === 'deposit') {
    update_post_meta($pid, 'hw_deposit_paid', (int)$total);
    update_post_meta($pid, 'hw_deposit_confirmed', 1);
    hw_po_maybe_to_maison_preparation($pid);
  } else { // full
    $dep = (float) get_post_meta($pid,'hw_deposit_amount',true);
    if ($dep > 0) {
      update_post_meta($pid, 'hw_deposit_paid', (int)max($dep, $total));
      update_post_meta($pid, 'hw_deposit_confirmed', 1);
    }
    $cur = get_post_meta($pid,'hw_po_status',true) ?: 'New';
    if (!hw_po_is_final_status($cur)) {
      hw_po_change_status($pid,'Maison Preparation','Full payment received');
    }
  }
}



/* ============================================================
 * AJAX Search (Modal)
 *  - latest: daftar terbaru (tanpa infinite)
 *  - search: Title > Category (longgar) > Deskripsi + infinite scroll
 *  - hanya menampilkan IN-STOCK
 *  - hasil search diurutkan terbaru→terlama; relevansi tie-breaker
 * ============================================================ */
add_action('wp_ajax_nopriv_hw_wc_ref_search', 'hw_wc_ref_search_cb');
add_action('wp_ajax_hw_wc_ref_search', 'hw_wc_ref_search_cb');

function hw_wc_ref_search_cb() {
  if (!class_exists('WooCommerce')) wp_send_json_error(['msg'=>'no_woo']);

  $q         = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
  $cat_slug  = isset($_POST['cat']) ? sanitize_text_field(wp_unslash($_POST['cat'])) : '';
  $per_page  = isset($_POST['per_page']) ? max(1, absint($_POST['per_page'])) : 10;
  $is_latest = !empty($_POST['latest']);
  $page      = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;

  $tax_q = [];
  if ($cat_slug) $tax_q[] = ['taxonomy'=>'product_cat','field'=>'slug','terms'=>$cat_slug];

  // hanya IN-STOCK
  $meta_instock = [[ 'key'=>'_stock_status','value'=>'instock','compare'=>'=' ]];

  // Builder item JSON
  $mk_item = function($id) {
    $prod = wc_get_product($id); if (!$prod) return null;
    $img = get_the_post_thumbnail_url($id, 'large'); if (!$img) $img = wc_placeholder_img_src('large');
    $price_html = wp_strip_all_tags($prod->get_price_html());
    $content = get_post_field('post_content', $id, 'raw');
    $dims = hw_ref_extract_dimensions_from_content($content);
    return ['id'=>$id,'title'=>get_the_title($id),'link'=>get_permalink($id),'image'=>esc_url($img),'price_html'=>$price_html,'dimensions'=>$dims];
  };

  /* Latest */
  if ($is_latest) {
    $ids = get_posts([
      'post_type'=>'product','posts_per_page'=>$per_page,'post_status'=>'publish',
      'orderby'=>'date','order'=>'DESC','tax_query'=>$tax_q,'meta_query'=>$meta_instock,'fields'=>'ids'
    ]);
    $out = []; foreach ($ids as $pid) { if($it = $mk_item($pid)) $out[] = $it; }
    wp_send_json_success($out);
  }

  /* Search */
  if (strlen($q) < 3) wp_send_json_success(['items'=>[], 'has_more'=>false]);

  $pool_size = 600;
  $ids_text = get_posts([
    'post_type'=>'product','posts_per_page'=>$pool_size,'post_status'=>'publish',
    's'=>$q,'ignore_sticky_posts'=>true,'tax_query'=>$tax_q,'meta_query'=>$meta_instock,'fields'=>'ids'
  ]);

  $matched_term_ids = hw_ref_match_cat_term_ids($q);
  $ids_cat = [];
  if ($matched_term_ids) {
    $tx = $tax_q; $tx[] = ['taxonomy'=>'product_cat','field'=>'term_id','terms'=>$matched_term_ids];
    $ids_cat = get_posts([
      'post_type'=>'product','posts_per_page'=>$pool_size,'post_status'=>'publish',
      'tax_query'=>$tx,'meta_query'=>$meta_instock,'fields'=>'ids'
    ]);
  }

  $ids = array_values(array_unique(array_merge($ids_text, $ids_cat)));
  if (count($ids) > $pool_size) $ids = array_slice($ids, 0, $pool_size);

  // skor relevansi + tanggal
  $q_l = function_exists('mb_strtolower') ? mb_strtolower($q) : strtolower($q);
  $q_norm = hw_ref_norm($q); $score = []; $dates = [];
  foreach ($ids as $pid) {
    $ttl = function_exists('mb_strtolower') ? mb_strtolower(get_the_title($pid)) : strtolower(get_the_title($pid));
    $cont = function_exists('mb_strtolower') ? mb_strtolower(get_post_field('post_content', $pid, 'raw')) : strtolower(get_post_field('post_content', $pid, 'raw'));
    $terms = get_the_terms($pid, 'product_cat'); $catMatch = false;
    if (!is_wp_error($terms) && $terms) {
      foreach ($terms as $t) {
        $nn = hw_ref_norm($t->name); $ns = hw_ref_norm($t->slug);
        if(($nn && (strpos($nn,$q_norm)!==false || strpos($q_norm,$nn)!==false)) || ($ns && (strpos($ns,$q_norm)!==false || strpos($q_norm,$ns)!==false))) { $catMatch=true; break; }
      }
    }
    $s = 0;
    if (strpos($ttl,$q_l)!==false) $s += 300;
    if ($catMatch)                $s += 200;
    if (strpos($cont,$q_l)!==false) $s += 100;
    $s += intval(get_post_time('U', true, $pid)) % 7;
    $score[$pid] = $s;
    $dates[$pid] = (int) get_post_time('U', true, $pid);
  }

  // sort: newer first, then relevance
  usort($ids, function($a,$b) use($score,$dates){
    $da=$dates[$a]??0; $db=$dates[$b]??0;
    if($da!==$db) return ($da>$db)?-1:1;
    $sa=$score[$a]??0; $sb=$score[$b]??0;
    if($sa===$sb) return 0;
    return ($sa>$sb)?-1:1;
  });

  $total = count($ids);
  $start = ($page - 1) * $per_page;
  $slice = array_slice($ids, $start, $per_page);

  $items = [];
  foreach ($slice as $pid) { if($it = $mk_item($pid)) $items[] = $it; }

  $has_more = ($start + $per_page) < $total;
  wp_send_json_success(['items'=>$items, 'has_more'=>$has_more]);
}

/* ============================================================
 * Shortcode MODAL /pre-order (tetap)
 * ============================================================ */
add_shortcode('hw_wc_product_reference', function($atts = []) {
  hw_po_enqueue_ref_assets();
  $a = shortcode_atts([
    'bind_name'    => 'req_product',
    'placeholder'  => 'Search Your Product',
    'min'          => 3,
    'per_page'     => 10,
    'initial'      => 8,
    'initial_mode' => 'dynamic',
    'label'        => 'Product Reference',
    'show_label'   => 'yes'
  ], $atts, 'hw_wc_product_reference');

  $cats = get_terms(['taxonomy'=>'product_cat','hide_empty'=>true,'parent'=>0]);
  $catOptions = '<option value="">All categories</option>';
  if (!is_wp_error($cats)) { foreach ($cats as $c) { $catOptions .= sprintf('<option value="%s">%s</option>', esc_attr($c->slug), esc_html($c->name)); } }

  $uid = 'hw-ref-'.wp_generate_uuid4();
  ob_start(); ?>
  <div id="<?php echo esc_attr($uid); ?>" class="hw-ref-root"
       data-min="<?php echo (int)$a['min']; ?>"
       data-per-page="<?php echo (int)$a['per_page']; ?>"
       data-initial="<?php echo (int)$a['initial']; ?>"
       data-initial-mode="<?php echo esc_attr($a['initial_mode']); ?>">
    <div class="hw-ref-wrap">
      <?php if($a['show_label']==='yes'): ?>
        <label class="hw-ref-label"><?php echo esc_html($a['label']); ?></label>
      <?php endif; ?>
      <div class="hw-ref-input">
        <svg class="ico" width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 21l-4.2-4.2M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="#777" stroke-width="1.6" stroke-linecap="round"/></svg>
        <input type="text" data-role="display" name="<?php echo esc_attr($a['bind_name']); ?>" placeholder="<?php echo esc_attr($a['placeholder']); ?>" readonly>
        <span class="hw-ref-clear" title="Clear" style="display:none"></span>
      </div>

      <!-- Hidden fields -->
      <input type="hidden" data-field="id"    name="<?php echo esc_attr($a['bind_name']); ?>_id">
      <input type="hidden" data-field="title" name="<?php echo esc_attr($a['bind_name']); ?>_title">
      <input type="hidden" data-field="link"  name="<?php echo esc_attr($a['bind_name']); ?>_link">
      <input type="hidden" data-field="image" name="<?php echo esc_attr($a['bind_name']); ?>_image">
      <input type="hidden" data-field="dim"   name="<?php echo esc_attr($a['bind_name']); ?>_dimensions">

      <div class="hw-ref-summary"></div>
    </div>

    <!-- Modal Glass -->
    <div class="hw-ref-overlay" aria-hidden="true">
      <div class="hw-ref-modal" role="dialog" aria-modal="true">
        <div class="hw-ref-head">
          <div class="hw-ref-cat">
            <select data-role="cat"><?php echo $catOptions; ?></select>
          </div>
          <div class="hw-ref-search">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M21 21l-4.2-4.2M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="#999" stroke-width="1.6" stroke-linecap="round"/></svg>
            <input type="text" data-role="q" placeholder="<?php echo esc_attr($a['placeholder']); ?>">
          </div>
          <div class="hw-ref-close" title="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="#666" stroke-width="1.6" stroke-linecap="round"/></svg>
          </div>
        </div>
        <div class="hw-ref-results"></div>
      </div>
    </div>
  </div>
  <?php
  return ob_get_clean();
});

/* ============================================================
 * Shortcode GLOBAL (navbar) – submit => halaman hasil pencarian
 * ============================================================ */
add_shortcode('hw_wc_product_search_global', function($atts = []){
  hw_po_enqueue_ref_assets();
  $a = shortcode_atts([
    'placeholder' => 'Search Your Product',
    'show_category' => 'yes',
    'query_var' => 'category', // param kategori pada URL
    'search_base' => '/',      // path action form
  ], $atts, 'hw_wc_product_search_global');

  $cats = get_terms(['taxonomy'=>'product_cat','hide_empty'=>true,'parent'=>0]);
  $catOptions = '<option value="">All categories</option>';
  if (!is_wp_error($cats)) { foreach ($cats as $c) { $catOptions .= sprintf('<option value="%s">%s</option>', esc_attr($c->slug), esc_html($c->name)); } }

  $action = home_url($a['search_base']);

  ob_start(); ?>
  <form class="hw-ref-global" action="<?php echo esc_url($action); ?>" method="get" role="search">
    <div class="hw-ref-head">
      <?php if ($a['show_category'] === 'yes'): ?>
        <div class="hw-ref-cat">
          <select name="<?php echo esc_attr($a['query_var']); ?>">
            <?php echo $catOptions; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="hw-ref-search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 21l-4.2-4.2M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="#999" stroke-width="1.6" stroke-linecap="round"/></svg>
        <input type="text" name="s" placeholder="<?php echo esc_attr($a['placeholder']); ?>">
        <input type="hidden" name="post_type" value="product">
      </div>
      <button class="hw-ref-submit" type="submit" aria-label="Search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M21 21l-4.2-4.2M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="#666" stroke-width="1.6" stroke-linecap="round"/></svg>
      </button>
    </div>
  </form>
  <?php
  return ob_get_clean();
});

/* ============================================================
 * "Search HW" – search global terpisah (tidak menimpa default)
 * ============================================================ */
if (!function_exists('hw_shortcode_search_hw')) {
  function hw_shortcode_search_hw($atts = []){
    hw_po_enqueue_ref_assets();
    $a = shortcode_atts([
      'placeholder'  => 'Search Your Product',
      'show_category'=> 'yes',
      'query_var'    => 'category',
      'search_base'  => '/',
    ], $atts, 'hw_search_hw');

    return do_shortcode(
      sprintf(
        '[hw_wc_product_search_global placeholder="%s" show_category="%s" query_var="%s" search_base="%s"]',
        esc_attr($a['placeholder']),
        esc_attr($a['show_category']),
        esc_attr($a['query_var']),
        esc_attr($a['search_base'])
      )
    );
  }
  add_shortcode('hw_search_hw', 'hw_shortcode_search_hw');
  add_shortcode('hw_search',    'hw_shortcode_search_hw'); // alias
}

/* ============================================================
 * INTEGRASI "Search HW" KE BLOK SEARCH HEADER (Styler)
 * - Mengganti HANYA search di header, sekali per halaman
 * - Search default di tempat lain tetap utuh
 * ============================================================ */
function hw_search_hw_markup_for_header(){
  hw_po_enqueue_ref_assets();
  return do_shortcode('[hw_search_hw placeholder="Search products..." show_category="yes" query_var="category" search_base="/"]');
}

function hw_po_maybe_override_search_form($form){
  if (is_admin()) return $form;

  static $header_replaced = false;
  if ($header_replaced) return $form;      // jangan ganggu form lain
  if (is_search())      return $form;      // biarkan form di halaman hasil tetap default

  $trace = function_exists('wp_debug_backtrace_summary') ? wp_debug_backtrace_summary(null, 0, false) : '';
  $maybe_header = (strpos($trace, 'header') !== false) || did_action('get_header');

  if ($maybe_header) {
    $header_replaced = true;
    return hw_search_hw_markup_for_header();
  }
  return $form;
}

function hw_po_print_header_search_styles(){
  ?>
  <style>
    .menu-item-hw-search { display:inline-block; vertical-align:middle; list-style:none; }
    .menu-item-hw-search .hw-ref-global { min-width:220px; }
    @media (min-width:992px){ .menu-item-hw-search .hw-ref-global { min-width:320px; } }
    .header .hw-ref-global, .site-header .hw-ref-global{width:100%}
  </style>
  <?php
}

if (hw_po_is_header_search_override_enabled()) {
  add_filter('get_search_form', 'hw_po_maybe_override_search_form', 20);
  add_action('wp_head', 'hw_po_print_header_search_styles');
}





/* ============================================================
 * Shortcode: Site-wide Search (semua post type)
 * - Tidak menyisipkan post_type => hasil bisa produk, post, page, dsb.
 * - Aman: tidak mengganggu shortcode/fungsi yang sudah ada.
 * ============================================================ */
add_shortcode('hw_search_sitewide', function($atts = []){
  hw_po_enqueue_ref_assets();
  $a = shortcode_atts([
    'placeholder'  => 'Search the site...',
    'search_base'  => '/',   // path action form (biasanya '/')
  ], $atts, 'hw_search_sitewide');

  $action = home_url($a['search_base']);

  ob_start(); ?>
  <form class="hw-ref-global" action="<?php echo esc_url($action); ?>" method="get" role="search">
    <div class="hw-ref-head">
      <div class="hw-ref-search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M21 21l-4.2-4.2M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="#999" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
        <input type="text" name="s" placeholder="<?php echo esc_attr($a['placeholder']); ?>">
        <!-- Tidak ada input hidden post_type -> site-wide -->
      </div>
      <button class="hw-ref-submit" type="submit" aria-label="Search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
          <path d="M21 21l-4.2-4.2M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="#666" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
      </button>
    </div>
  </form>
  <?php
  return ob_get_clean();
});






