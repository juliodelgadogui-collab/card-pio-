package br.com.eventmenu.go.data

data class ReceiptItem(val name:String,val quantity:Double,val unitPriceCents:Int,val totalCents:Int)
data class ReceiptPayment(val id:Int,val provider:String,val method:String,val amountCents:Int,val status:String,val verifiedAt:String)
data class ReceiptQr(val enabled:Boolean=false,val payload:String="",val label:String="QR DO PEDIDO",val help:String="")
data class ReceiptSections(val business:Boolean=true,val customer:Boolean=true,val items:Boolean=true,val payments:Boolean=true,val operator:Boolean=true,val customerPhone:Boolean=true,val event:Boolean=true)
data class ReceiptAdvertisement(val enabled:Boolean=false,val label:String="PUBLICIDADE",val headline:String="",val body:String="",val imageUrl:String="",val targetUrl:String="",val campaign:String="")
data class ReceiptPresentation(val schemaVersion:Int=1,val paperWidth:String="80",val title:String="COMPROVANTE NÃO FISCAL",val subtitle:String="Documento operacional EventMenu",val footer:String="Obrigado pela preferência!",val qr:ReceiptQr=ReceiptQr(),val sections:ReceiptSections=ReceiptSections(),val advertisement:ReceiptAdvertisement=ReceiptAdvertisement())
data class OrderReceipt(val receiptNumber:String,val tenantName:String,val orderId:Int,val channel:String,val status:String,val paymentStatus:String,val customerName:String,val customerPhone:String,val tableName:String,val subtotalCents:Int,val discountCents:Int,val deliveryFeeCents:Int,val totalCents:Int,val paidCents:Int,val createdAt:String,val items:List<ReceiptItem>,val payments:List<ReceiptPayment>,val presentation:ReceiptPresentation=ReceiptPresentation())
data class GroupReceiptAllocation(val orderId:Int,val paymentId:Int,val amountCents:Int,val paymentStatus:String,val verifiedAt:String)
data class GroupReceiptItem(val orderItemId:Int,val orderId:Int,val name:String,val quantity:Double,val amountCents:Int)
data class GroupReceipt(val receiptNumber:String,val tenantName:String,val groupId:Int,val tabId:Int?,val tableName:String,val tabLabel:String,val operatorName:String,val splitType:String,val method:String,val provider:String,val status:String,val amountCents:Int,val confirmedCents:Int,val providerPaymentId:String,val verifiedAt:String,val createdAt:String,val allocations:List<GroupReceiptAllocation>,val items:List<GroupReceiptItem>)
