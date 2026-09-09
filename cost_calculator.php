<?php
require __DIR__.'/bootstrap.php';
require_login();
$tid=require_tour();

$today=date('Y-m-d');

$q=db()->prepare("SELECT COUNT(*) FROM passengers WHERE tour_id=? AND status='ACTIVE'");
$q->execute([$tid]);
$bookedPassengers=(int)$q->fetchColumn();

$q=db()->prepare("SELECT id,room_no,room_type,capacity FROM rooms WHERE tour_id=? ORDER BY room_no");
$q->execute([$tid]);
$rooms=$q->fetchAll();

$roomsByType=[];
foreach($rooms as $r){
    $key=(string)$r['room_type'];
    if(!isset($roomsByType[$key])){
        $roomsByType[$key]=['label'=>room_label($key),'rooms'=>0,'capacity'=>0];
    }
    $roomsByType[$key]['rooms']++;
    $roomsByType[$key]['capacity']+=(int)$r['capacity'];
}

$q=db()->prepare("SELECT id,name,seat_count FROM buses WHERE tour_id=? AND active=1 ORDER BY id");
$q->execute([$tid]);
$buses=$q->fetchAll();

if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf();
    try{
        $action=$_POST['action']??'';
        $expenseDate=$_POST['expense_date']??$today;
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expenseDate)) $expenseDate=$today;

        if($action==='save_costs'){
            $busRents=$_POST['bus_rent']??[];
            $roomRents=$_POST['room_rent']??[];
            $mealPrices=$_POST['meal_price']??[];
            $mealPassengers=max(0,(int)($_POST['meal_passengers']??$bookedPassengers));

            $grandTotal=0;
            foreach($buses as $b){
                $grandTotal += max(0,(float)($busRents[$b['id']]??0));
            }

            foreach($roomsByType as $typeKey=>$info){
                $rent=max(0,(float)($roomRents[$typeKey]??0));
                $grandTotal += $rent*(int)$info['rooms'];
            }

            foreach(['Breakfast','Lunch','Dinner','Snacks'] as $meal){
                $unit=max(0,(float)($mealPrices[$meal]??0));
                $grandTotal += $unit*$mealPassengers;
            }

            /*
             * The Cost Calculator now creates ONE expense only:
             * the Grand Total. Re-saving updates that same expense.
             *
             * Legacy calculator line-items from older versions are removed
             * so the Expenses page does not keep the old bus/room/meal rows.
             */
            db()->beginTransaction();

            $legacy=db()->prepare("
                DELETE FROM expenses
                WHERE tour_id=?
                  AND paid_by='Cost Calculator'
                  AND (
                      category IN ('Transport / Bus','Room Cost','Food & Meals')
                      OR description LIKE 'Bus Rental · %'
                      OR description LIKE 'Room Cost · %'
                      OR description LIKE 'Breakfast · %'
                      OR description LIKE 'Lunch · %'
                      OR description LIKE 'Dinner · %'
                      OR description LIKE 'Snacks · %'
                  )
            ");
            $legacy->execute([$tid]);

            $find=db()->prepare("
                SELECT id
                FROM expenses
                WHERE tour_id=?
                  AND category='Tour Cost'
                  AND paid_by='Cost Calculator'
                  AND description='Tour Cost Calculator — Grand Total'
                ORDER BY id ASC
            ");
            $find->execute([$tid]);
            $rows=$find->fetchAll();

            if($grandTotal>0){
                $description='Tour Cost Calculator — Grand Total';

                if($rows){
                    $keepId=(int)$rows[0]['id'];
                    $up=db()->prepare("
                        UPDATE expenses
                        SET description=?, amount=?, expense_date=?
                        WHERE id=? AND tour_id=?
                    ");
                    $up->execute([$description,$grandTotal,$expenseDate,$keepId,$tid]);

                    if(count($rows)>1){
                        $del=db()->prepare("DELETE FROM expenses WHERE id=? AND tour_id=?");
                        for($i=1;$i<count($rows);$i++){
                            $del->execute([(int)$rows[$i]['id'],$tid]);
                        }
                    }

                    audit('UPDATE_AUTO_EXPENSE','expense',$keepId,'Cost Calculator Grand Total');
                }else{
                    $ins=db()->prepare("
                        INSERT INTO expenses
                        (tour_id,category,description,amount,paid_by,expense_date,created_by)
                        VALUES(?,?,?,?,?,?,?)
                    ");
                    $ins->execute([
                        $tid,
                        'Tour Cost',
                        $description,
                        $grandTotal,
                        'Cost Calculator',
                        $expenseDate,
                        $_SESSION['admin_id']??null
                    ]);
                    audit('ADD_AUTO_EXPENSE','expense',(int)db()->lastInsertId(),'Cost Calculator Grand Total');
                }

                flash('success','Grand Total '.money($grandTotal).' saved to Expenses as one expense. Saving again will update the same expense.');
            }else{
                flash('success','Grand Total is ৳0.00, so nothing was added to Expenses.');
            }

            db()->commit();
        }elseif($action==='remove_costs'){
            /*
             * Remove only the expense generated by the Cost Calculator.
             * Manual expenses are never touched.
             */
            db()->beginTransaction();

            $q=db()->prepare("
                SELECT id
                FROM expenses
                WHERE tour_id=?
                  AND category='Tour Cost'
                  AND paid_by='Cost Calculator'
                  AND description='Tour Cost Calculator — Grand Total'
            ");
            $q->execute([$tid]);
            $rows=$q->fetchAll();

            $del=db()->prepare("DELETE FROM expenses WHERE id=? AND tour_id=?");
            foreach($rows as $row){
                $del->execute([(int)$row['id'],$tid]);
                audit('DELETE_AUTO_EXPENSE','expense',(int)$row['id'],'Cost Calculator Grand Total removed');
            }

            db()->commit();
            flash('success','Cost Calculator Grand Total removed from Expenses. Your manual expenses were not changed.');
        }
    }catch(Throwable $e){
        if(db()->inTransaction()) db()->rollBack();
        flash('danger',$e->getMessage());
    }
    redirect('cost_calculator.php');
}
?>
<!doctype html>
<html>
<head><?php include __DIR__.'/partials/head.php';?><style>
.room-category-total{white-space:nowrap}
.cost-note{
  border:1px solid #dfeee4;
  background:#f7fcf9;
  border-radius:12px;
  padding:12px 14px;
  margin-top:14px;
}
.cost-note b{color:#075c38}
@media(max-width:700px){
  #costForm .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  #costForm table{min-width:720px}
  #costForm .table-wrap input{min-width:150px}
  .calculator-actions{display:flex;flex-wrap:wrap}
}
</style>
</head>
<body>
<?php include __DIR__.'/partials/nav.php';?>
<main class="wrap">
<?php flash_render();?>

<section class="card">
  <div class="head">
    <div>
      <div class="eyebrow">TOUR COST CALCULATOR</div>
      <h1>Tour Cost Calculator</h1>
      <p class="muted">Enter bus, room and meal costs here. The calculation updates automatically.</p>
    </div>
    <a class="btn secondary" href="expenses.php">← Expenses</a>
  </div>

  <div class="stats">
    <div class="stat"><span>Booked Passengers</span><b id="bookedCount"><?=$bookedPassengers?></b></div>
    <div class="stat"><span>Bus Cost</span><b id="busTotal">৳0.00</b></div>
    <div class="stat"><span>Room Cost</span><b id="roomTotal">৳0.00</b></div>
    <div class="stat"><span>Food Cost</span><b id="foodTotal">৳0.00</b></div>
    <div class="stat"><span>Grand Total</span><b id="grandTotal">৳0.00</b></div>
  </div>

  <div class="cost-note">
    <b>Expenses integration:</b>
    Only the <strong>Grand Total</strong> will be added to the Expenses page.
    Saving again updates the existing Cost Calculator expense instead of creating new line items.
    You can remove the calculator-generated expense whenever you want.
  </div>
</section>

<form method="post" id="costForm">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<input type="hidden" name="action" value="save_costs">

<section class="card">
  <div class="head">
    <div>
      <h2>🚌 Bus Rental Cost</h2>
      <p class="muted">Enter the rental cost separately for each bus.</p>
    </div>
  </div>
  <?php if(!$buses): ?>
    <div class="alert">No active buses have been added for this tour yet.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Bus</th><th>Seats</th><th>Bus Rental</th></tr></thead>
      <tbody>
      <?php foreach($buses as $b): ?>
        <tr>
          <td><b><?=h($b['name'])?></b></td>
          <td><?=h($b['seat_count'])?></td>
          <td><input class="cost-bus" type="number" min="0" step="0.01" name="bus_rent[<?=$b['id']?>]" value="0" placeholder="0"></td>
        </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="card">
  <div class="head">
    <div>
      <h2>🏨 Room Cost</h2>
      <p class="muted">Set one rent for each room category. All rooms in that category use the same rent.</p>
    </div>
  </div>

  <?php if(!$roomsByType): ?>
    <div class="alert">No rooms have been added for this tour yet.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Room Category</th><th>Total Rooms</th><th>Total Capacity</th><th>Room Rent / Room</th><th>Total Cost</th></tr>
      </thead>
      <tbody>
      <?php foreach($roomsByType as $typeKey=>$info): ?>
        <tr>
          <td><b><?=h($info['label'])?></b></td>
          <td><?=h($info['rooms'])?></td>
          <td><?=h($info['capacity'])?></td>
          <td>
            <input class="cost-room" data-room-count="<?=h($info['rooms'])?>" type="number" min="0" step="0.01"
                   name="room_rent[<?=h($typeKey)?>]" value="0" placeholder="0">
          </td>
          <td><b class="room-category-total" data-room-type="<?=h($typeKey)?>">৳0.00</b></td>
        </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
  <div class="muted" style="margin-top:10px">
    Room total = number of rooms × rent per room. Adding or removing rooms on the Rooms page will automatically change the room count here.
  </div>
  <?php endif;?>
</section>

<section class="card">
  <div class="head">
    <div>
      <h2>🍽️ Food & Meals</h2>
      <p class="muted">Set the number of people taking the meal package. It starts from the active booked passenger count, but you can reduce or increase it for children or others who do not take the package.</p>
      <label style="display:block;max-width:360px;margin-top:12px">Meal Package Passengers
        <input id="mealPassengers" type="number" min="0" step="1" name="meal_passengers" value="<?=$bookedPassengers?>" required>
        <small class="muted">Currently booked: <?=$bookedPassengers?> passenger(s)</small>
      </label>
    </div>
  </div>

  <div class="formgrid">
    <?php foreach(['Breakfast','Lunch','Dinner','Snacks'] as $meal): ?>
      <label>
        <?=$meal?> — Price / Passenger
        <input class="meal-price" type="number" min="0" step="0.01"
               name="meal_price[<?=h($meal)?>]" value="0" placeholder="0">
        <small class="muted"><span class="meal-total" data-meal="<?=h($meal)?>">৳0.00</span> total</small>
      </label>
    <?php endforeach;?>
  </div>
</section>

<section class="card">
  <div class="formgrid">
    <label>Expense Date<input type="date" name="expense_date" value="<?=h($today)?>" required></label>
    <div>
      <div class="muted" style="margin-bottom:8px">Calculation</div>
      <div><b>Bus:</b> <span id="busTotal2">৳0.00</span></div>
      <div><b>Room:</b> <span id="roomTotal2">৳0.00</span></div>
      <div><b>Food:</b> <span id="foodTotal2">৳0.00</span></div>
      <div style="font-size:1.15rem;margin-top:6px"><b>Grand Total:</b> <span id="grandTotal2">৳0.00</span></div>
    </div>
  </div>

  <div class="row-actions calculator-actions" style="margin-top:18px;gap:10px">
    <button class="btn primary" type="submit">💾 Add / Update Grand Total in Expenses</button>
  </div>

  <div class="row-actions calculator-actions" style="margin-top:10px;gap:10px">
    <button class="btn danger" type="submit" name="action" value="remove_costs"
            onclick="return confirm('Remove the Cost Calculator Grand Total from Expenses? Manual expenses will not be affected.')">
      🗑 Remove Grand Total from Expenses
    </button>
  </div>

  <p class="muted" style="margin-top:10px">
    Only one Cost Calculator expense is maintained. Re-saving updates it.
    Removing it does not remove or change any manually entered expense.
  </p>
</section>
</form>

<script>
(function(){
  const booked=<?=json_encode($bookedPassengers)?>;
  const tourId=<?=json_encode($tid)?>;
  const storageKey='gmjs_cost_calculator_v3_'+tourId;
  const money=n=>'৳'+Number(n||0).toLocaleString('en-BD',{minimumFractionDigits:2,maximumFractionDigits:2});

  const buses=[...document.querySelectorAll('.cost-bus')];
  const rooms=[...document.querySelectorAll('.cost-room')];
  const meals=[...document.querySelectorAll('.meal-price')];
  const mealPassengersInput=document.getElementById('mealPassengers');

  function saveValues(){
    const data={
      buses:{},
      rooms:{},
      meals:{},
      mealPassengers:mealPassengersInput?.value||booked,
      expenseDate:document.querySelector('[name="expense_date"]')?.value||''
    };
    buses.forEach(i=>data.buses[i.name]=i.value);
    rooms.forEach(i=>data.rooms[i.name]=i.value);
    meals.forEach(i=>data.meals[i.name]=i.value);
    try{localStorage.setItem(storageKey,JSON.stringify(data));}catch(e){}
  }

  function restoreValues(){
    try{
      const data=JSON.parse(localStorage.getItem(storageKey)||'null');
      if(!data)return;

      [...buses,...rooms,...meals].forEach(i=>{
        const group=i.name.startsWith('bus_rent')?'buses':
                     i.name.startsWith('room_rent')?'rooms':'meals';
        if(data[group] && data[group][i.name]!==undefined){
          i.value=data[group][i.name];
        }
      });

      if(mealPassengersInput && data.mealPassengers!==undefined){
        mealPassengersInput.value=data.mealPassengers;
      }

      const d=document.querySelector('[name="expense_date"]');
      if(d && data.expenseDate) d.value=data.expenseDate;
    }catch(e){}
  }

  function calculate(){
    let bus=0, room=0, food=0;

    buses.forEach(i=>bus+=Math.max(0,Number(i.value)||0));

    rooms.forEach(i=>{
      const rent=Math.max(0,Number(i.value)||0);
      const count=Number(i.dataset.roomCount)||0;
      const total=rent*count;
      room+=total;

      const type=i.name.match(/room_rent\[([^\]]+)\]/)?.[1];
      const target=type
        ? document.querySelector('.room-category-total[data-room-type="'+CSS.escape(type)+'"]')
        : null;

      if(target) target.textContent=money(total);
    });

    const mealPassengers=Math.max(0,Number(mealPassengersInput?.value)||0);

    meals.forEach(i=>{
      const unit=Math.max(0,Number(i.value)||0);
      const total=unit*mealPassengers;
      food+=total;

      const match=i.name.match(/\[([^\]]+)\]/);
      const target=match
        ? document.querySelector('.meal-total[data-meal="'+CSS.escape(match[1])+'"]')
        : null;

      if(target) target.textContent=money(total);
    });

    const grand=bus+room+food;

    document.getElementById('busTotal').textContent=money(bus);
    document.getElementById('roomTotal').textContent=money(room);
    document.getElementById('foodTotal').textContent=money(food);
    document.getElementById('grandTotal').textContent=money(grand);

    document.getElementById('busTotal2').textContent=money(bus);
    document.getElementById('roomTotal2').textContent=money(room);
    document.getElementById('foodTotal2').textContent=money(food);
    document.getElementById('grandTotal2').textContent=money(grand);
  }

  restoreValues();

  [...buses,...rooms,...meals].forEach(i=>{
    i.addEventListener('input',()=>{
      calculate();
      saveValues();
    });
  });

  mealPassengersInput?.addEventListener('input',()=>{
    calculate();
    saveValues();
  });

  document.querySelector('[name="expense_date"]')?.addEventListener('change',saveValues);

  calculate();
  document.getElementById('costForm')?.addEventListener('submit',saveValues);
})();
</script>
</body>
</html>
