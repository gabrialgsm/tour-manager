/* FINAL WEB APP CORE: never uses getActiveSpreadsheet() */
function webBook_(){
  const id=PropertiesService.getScriptProperties().getProperty('SPREADSHEET_ID');
  if(!id) throw Error('GMJS spreadsheet is not connected.');
  return SpreadsheetApp.openById(id);
}
function webSheet_(n){
  const s=webBook_().getSheetByName(n);
  if(!s) throw Error('Missing sheet: '+n);
  return s;
}
function webRows_(n){
  const s=webSheet_(n),v=s.getDataRange().getValues();
  if(v.length<2)return [];
  const h=v[0];
  return v.slice(1).filter(r=>r.some(x=>x!==''&&x!==null)).map(r=>Object.fromEntries(h.map((x,i)=>[x,r[i]])));
}
function webId_(p){return p+'-'+Utilities.getUuid().slice(0,8).toUpperCase()}
function webRow_(sheet,key,value){
  const s=webSheet_(sheet),v=s.getDataRange().getValues(),h=v[0],i=h.indexOf(key);
  for(let n=1;n<v.length;n++) if(String(v[n][i])===String(value)) return {row:n+1,values:v[n],headers:h};
  return null;
}
function webRow2_(sheet,k1,v1,k2,v2){
  const s=webSheet_(sheet),v=s.getDataRange().getValues(),h=v[0],a=h.indexOf(k1),b=h.indexOf(k2);
  for(let n=1;n<v.length;n++) if(String(v[n][a])===String(v1)&&String(v[n][b])===String(v2)) return {row:n+1,values:v[n],headers:h};
  return null;
}
function webEnsureSystem_(){
  const book=webBook_();
  Object.keys(HEADERS).forEach(n=>{
    let s=book.getSheetByName(n);
    if(!s){s=book.insertSheet(n);s.getRange(1,1,1,HEADERS[n].length).setValues([HEADERS[n]]);s.setFrozenRows(1);}
  });
  const ts=webSheet_('Tours');
  let tours=webRows_('Tours');
  let t=tours.find(x=>String(x.Status).toUpperCase()==='OPEN')||tours[0];
  if(!t){
    const tid=webId_('TOUR');
    ts.appendRow([tid,'Kuakata & Sundarbans Tour 2026','2026-09-03','2026-09-05','2026-08-25',4000,4500,2000,'OPEN',new Date()]);
    t=webRow_('Tours','TourID',tid).values;
    const h=webSheet_('Tours').getDataRange().getValues()[0]; t=Object.fromEntries(h.map((x,i)=>[x,t[i]]));
  }
  const bs=webSheet_('Buses');
  let buses=webRows_('Buses').filter(x=>String(x.TourID)===String(t.TourID)&&String(x.Status).toUpperCase()==='ACTIVE');
  const layout=[];'ABCDEFGHIJ'.split('').forEach(r=>[1,2,3,4,5].forEach(c=>layout.push({seat:r+c,row:r,col:c,type:'STANDARD'})));
  ['BUS 1','BUS 2'].forEach((name,idx)=>{
    if(!buses.some(x=>x.BusName===name)){
      const bid=webId_(idx===0?'BUS1':'BUS2');
      bs.appendRow([bid,t.TourID,name,50,JSON.stringify(layout),'ACTIVE',new Date()]);
      const out=layout.map(x=>[webId_('SEAT'),t.TourID,bid,x.seat,x.row,x.col,x.type,'AVAILABLE','',new Date()]);
      webSheet_('Seats').getRange(webSheet_('Seats').getLastRow()+1,1,out.length,10).setValues(out);
    }
  });
  return t;
}
function webGetAppData(token){
  auth_(token);
  const t=webEnsureSystem_();
  const buses=webRows_('Buses').filter(x=>String(x.TourID)===String(t.TourID)&&String(x.Status).toUpperCase()==='ACTIVE');
  const seats=webRows_('Seats').filter(x=>String(x.TourID)===String(t.TourID));
  const passengers=webRows_('Passengers').filter(x=>String(x.TourID)===String(t.TourID)&&x.Status!=='CANCELLED');
  const expenses=webRows_('Expenses').filter(x=>String(x.TourID)===String(t.TourID));
  const rooms=webRows_('Rooms').filter(x=>String(x.TourID)===String(t.TourID));
  buses.forEach(b=>{try{b.Layout=JSON.parse(b.LayoutJSON||'[]')}catch(e){b.Layout=[]}b.Seats=seats.filter(s=>String(s.BusID)===String(b.BusID))});
  const collection=passengers.reduce((n,p)=>n+Number(p.Paid||0),0);
  const due=passengers.reduce((n,p)=>n+Number(p.Due||0),0);
  const expense=expenses.reduce((n,e)=>n+Number(e.Amount||0),0);
  return {tour:t,buses,passengers,expenses,rooms,summary:{totalSeats:seats.length,booked:seats.filter(s=>s.Status==='BOOKED').length,available:seats.filter(s=>s.Status==='AVAILABLE').length,collection,due,expense,balance:collection-expense,paidCount:passengers.filter(p=>Number(p.Due)<=0).length,dueCount:passengers.filter(p=>Number(p.Due)>0).length},roomTypes:CONFIG.roomTypes};
}
function webBookPassenger(d,token){
  auth_(token); const lock=LockService.getScriptLock(); lock.waitLock(10000);
  try{
    const t=webEnsureSystem_();
    const seat=webRow2_('Seats','BusID',d.BusID,'SeatNo',d.SeatNo);
    if(!seat)throw Error('Seat not found.');
    if(seat.values[7]==='BOOKED'&&seat.values[8]!==d.PassengerID)throw Error('Seat already booked.');
    const pid=d.PassengerID||webId_('P'), total=Number(d.TotalFee||t.PackageFee), paid=Number(d.Paid||0), discount=Number(d.Discount||0), due=Math.max(total-paid,0), stamp=new Date();
    const data=[pid,t.TourID,d.BusID,d.SeatNo,d.Name||'',d.Phone||'',d.Address||'',d.BloodGroup||'',d.EmergencyContact||'',d.Departure||'Dhapari',d.RoomType||'AC_4_BED',d.PackageType||'STANDARD',total,paid,due,discount,d.Notes||'','ACTIVE',stamp,stamp];
    const existing=webRow_('Passengers','PassengerID',pid);
    if(existing)webSheet_('Passengers').getRange(existing.row,1,1,data.length).setValues([data]); else webSheet_('Passengers').appendRow(data);
    webSheet_('Seats').getRange(seat.row,8,1,3).setValues([['BOOKED',pid,stamp]]);
    if(paid>0&&!existing)webSheet_('Payments').appendRow([webId_('PAY'),pid,t.TourID,paid,d.PaymentMethod||'Cash',stamp,d.ReceivedBy||'Admin','Booking payment']);
    return webGetAppData(token);
  }finally{lock.releaseLock()}
}
function webAddPayment(d,token){
  auth_(token);const p=webRow_('Passengers','PassengerID',d.PassengerID);if(!p)throw Error('Passenger not found.');
  const amount=Number(d.Amount),remaining=Math.max(Number(p.values[12])-Number(p.values[13]),0);if(amount<=0||amount>remaining)throw Error('Invalid payment amount.');
  const paid=Number(p.values[13])+amount,due=Math.max(Number(p.values[12])-paid,0);
  webSheet_('Payments').appendRow([webId_('PAY'),d.PassengerID,p.values[1],amount,d.Method||'Cash',new Date(),d.ReceivedBy||'Admin',d.Note||'']);
  webSheet_('Passengers').getRange(p.row,14,1,2).setValues([[paid,due]]);
  return webGetAppData(token);
}
function webAddExpense(d,token){
  auth_(token);const t=webEnsureSystem_();if(!d.Category||Number(d.Amount)<=0)throw Error('Category and amount required.');
  webSheet_('Expenses').appendRow([webId_('EXP'),t.TourID,d.Date||new Date(),d.Category,d.Description||'',Number(d.Amount),d.PaidBy||'',d.Note||'']);
  return webGetAppData(token);
}
function webRepairStatus(){
  const t=webEnsureSystem_();
  Logger.log('TOUR='+JSON.stringify(t));
  Logger.log('BUSES='+webRows_('Buses').filter(x=>String(x.TourID)===String(t.TourID)).length);
  Logger.log('SEATS='+webRows_('Seats').filter(x=>String(x.TourID)===String(t.TourID)).length);
  return 'OK';
}
