export type ExecutiveNarrative = { title: string; insight: string; action: string };
export type ExecutiveRank = { name?: string; inventory_id?: string; brand?: string; reason?: string; revenue_at_risk: number; line_count: number };
export type BackorderExecutiveReport = {
  metrics: { revenue_at_risk: number; ready_to_release: number; blocked_no_stock: number; open_lines: number; open_skus: number; open_customers: number; open_orders: number };
  breakdowns: { segments: Record<string, { revenue_at_risk: number }>; brands: ExecutiveRank[]; customers: ExecutiveRank[]; skus: ExecutiveRank[]; reasons: ExecutiveRank[] };
  narrative: ExecutiveNarrative[]; background_image: string | null; filters: Record<string,string>; generated_at: string; cached: boolean;
  ai_status: { narrative: string; image: string; model: string | null };
};

const W=1536,H=1024;
const money=(value:number)=>`KES ${Math.round(value).toLocaleString("en-KE")}`;

function rounded(ctx:CanvasRenderingContext2D,x:number,y:number,w:number,h:number,r=22){ctx.beginPath();ctx.roundRect(x,y,w,h,r);}
function text(ctx:CanvasRenderingContext2D,value:string,x:number,y:number,maxWidth:number,lineHeight:number,maxLines=2){const words=value.split(/\s+/);let line="",row=0;for(const word of words){const next=line?`${line} ${word}`:word;if(ctx.measureText(next).width>maxWidth&&line){ctx.fillText(line,x,y+row*lineHeight);row++;line=word;if(row>=maxLines-1)break;}else line=next;}if(line&&row<maxLines)ctx.fillText(line,x,y+row*lineHeight);}
async function loadImage(src:string):Promise<HTMLImageElement>{return new Promise((resolve,reject)=>{const img=new Image();img.onload=()=>resolve(img);img.onerror=reject;img.src=src;});}

export async function renderBackorderExecutiveReport(report:BackorderExecutiveReport):Promise<string>{
  const canvas=document.createElement("canvas");canvas.width=W;canvas.height=H;const ctx=canvas.getContext("2d");if(!ctx)throw new Error("Canvas is unavailable in this browser.");
  const gradient=ctx.createLinearGradient(0,0,W,H);gradient.addColorStop(0,"#071b33");gradient.addColorStop(.55,"#0b3550");gradient.addColorStop(1,"#0b5b62");ctx.fillStyle=gradient;ctx.fillRect(0,0,W,H);
  if(report.background_image){try{const bg=await loadImage(report.background_image);ctx.globalAlpha=.34;ctx.drawImage(bg,0,0,W,H);ctx.globalAlpha=1;}catch{/* deterministic background remains */}}
  ctx.fillStyle="rgba(3,15,30,.64)";ctx.fillRect(0,0,W,H);
  try{const logo=await loadImage("/kim-fay-logo.png");ctx.drawImage(logo,64,42,92,66);}catch{/* report title remains sufficient */}
  ctx.fillStyle="#fff";ctx.font="700 35px Inter, Arial";ctx.fillText("BACKORDER EXECUTIVE REPORT",180,74);ctx.font="500 17px Inter, Arial";ctx.fillStyle="#b9d7e4";ctx.fillText("Kim-Fay Sight · Active exposure, not lost sales",180,104);
  const from=report.filters.date_from??"All dates",to=report.filters.date_to??"Current";ctx.textAlign="right";ctx.fillText(`${from} — ${to}`,1472,70);ctx.fillText(new Date(report.generated_at).toLocaleString("en-KE"),1472,98);ctx.textAlign="left";
  const cards=[{label:"REVENUE AT RISK",value:report.metrics.revenue_at_risk,color:"#fb7185"},{label:"READY TO RELEASE",value:report.metrics.ready_to_release,color:"#34d399"},{label:"BLOCKED — NO STOCK",value:report.metrics.blocked_no_stock,color:"#f59e0b"}];
  cards.forEach((card,i)=>{const x=64+i*482;rounded(ctx,x,142,450,136);ctx.fillStyle="rgba(255,255,255,.10)";ctx.fill();ctx.fillStyle=card.color;ctx.fillRect(x,142,7,136);ctx.fillStyle="#c6dce5";ctx.font="700 15px Inter, Arial";ctx.fillText(card.label,x+30,182);ctx.fillStyle="#fff";ctx.font="700 34px Inter, Arial";ctx.fillText(money(card.value),x+30,235);});
  ctx.fillStyle="#d6e7ee";ctx.font="600 16px Inter, Arial";ctx.fillText(`${report.metrics.open_lines.toLocaleString()} open lines`,64,314);ctx.fillText(`${report.metrics.open_skus.toLocaleString()} SKUs`,224,314);ctx.fillText(`${report.metrics.open_customers.toLocaleString()} customers`,344,314);ctx.fillText(`${report.metrics.open_orders.toLocaleString()} orders`,504,314);
  const panel=(x:number,y:number,w:number,h:number,title:string)=>{rounded(ctx,x,y,w,h);ctx.fillStyle="rgba(255,255,255,.09)";ctx.fill();ctx.fillStyle="#fff";ctx.font="700 19px Inter, Arial";ctx.fillText(title,x+24,y+34);};
  panel(64,344,450,268,"Exposure mix");const total=Math.max(1,report.metrics.revenue_at_risk);const m=report.breakdowns.segments.manufactured?.revenue_at_risk??0,t=report.breakdowns.segments.trading?.revenue_at_risk??0;
  [["Manufactured",m,"#22c55e"],["Trading / Partners",t,"#a78bfa"]].forEach(([label,value,color],i)=>{const y=405+i*82;ctx.fillStyle="#c6dce5";ctx.font="600 16px Inter, Arial";ctx.fillText(String(label),88,y);ctx.textAlign="right";ctx.fillStyle="#fff";ctx.fillText(money(Number(value)),486,y);ctx.textAlign="left";ctx.fillStyle="rgba(255,255,255,.14)";ctx.fillRect(88,y+18,398,18);ctx.fillStyle=String(color);ctx.fillRect(88,y+18,398*Math.min(1,Number(value)/total),18);});
  const rankings=(x:number,title:string,rows:ExecutiveRank[],key:"brand"|"name"|"inventory_id")=>{panel(x,344,450,268,title);rows.slice(0,5).forEach((row,i)=>{const y=400+i*39;ctx.fillStyle="#d7e6ec";ctx.font="500 14px Inter, Arial";ctx.fillText(String(row[key]??"Unclassified").slice(0,31),x+24,y);ctx.textAlign="right";ctx.fillStyle="#fff";ctx.fillText(money(Number(row.revenue_at_risk)),x+426,y);ctx.textAlign="left";});};
  rankings(546,"Top brands",report.breakdowns.brands,"brand");rankings(1028,"Top customers",report.breakdowns.customers,"name");
  panel(64,638,1408,292,"Executive focus");report.narrative.slice(0,3).forEach((item,i)=>{const x=88+i*458;ctx.fillStyle=i===0?"#67e8f9":i===1?"#86efac":"#fcd34d";ctx.font="700 17px Inter, Arial";ctx.fillText(item.title,x,690);ctx.fillStyle="#d9e8ee";ctx.font="500 14px Inter, Arial";text(ctx,item.insight,x,724,410,21,3);ctx.fillStyle="#fff";ctx.font="700 14px Inter, Arial";text(ctx,`Action: ${item.action}`,x,802,410,21,3);});
  ctx.fillStyle="#9fc1cf";ctx.font="500 13px Inter, Arial";ctx.fillText("CONFIDENTIAL · Generated from the user’s scoped Sight data · Figures are rendered from canonical backorder metrics",64,976);ctx.textAlign="right";ctx.fillText(report.ai_status.image==="generated"?`AI visual · ${report.ai_status.model??"OpenAI"}`:"Deterministic visual fallback",1472,976);
  return canvas.toDataURL("image/png");
}
