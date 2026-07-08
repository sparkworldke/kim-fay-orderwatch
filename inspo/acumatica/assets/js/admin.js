jQuery(document).ready( function() {

    jQuery("#schedule_price_update").click( function(e) {
    //    e.preventDefault(); 
       
    
       jQuery.ajax({
          type : "post",
          dataType : "json",
          url : myAjax.ajaxurl,
          data : {action: "schedule_price_update"},
          success: function(response) {
            console.log(response.type);
             if(response.type == "success") {
                alert('Update schedued!');
             }
             else {
                alert("Update could not be schedued!");
             }
          },error: function(e){
            console.log(e);
            alert('Update schedued!');
          }
       })   
    
    })
    
    jQuery("#schedule_product_update").click( function(e) {
    //    e.preventDefault(); 
       
    
       jQuery.ajax({
          type : "post",
          dataType : "json",
          url : myAjax.ajaxurl,
          data : {action: "schedule_product_update"},
          success: function(response) {
            console.log(response.type);
             if(response.type == "success") {
                alert('Update schedued!');
             }
             else {
                alert("Update could not be schedued!");
             }
          },error: function(e){
            console.log(e);
            alert('Update schedued!');
          }
       })   
    
    })
})