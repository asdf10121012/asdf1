<?php
/*
Plugin Name: YAZGU - YORUM BUTONU TIKLA - YENİ SEKMEDE (Minimal, Infinite Scroll Fix)
Description: Yorum butonuna tıklayınca paylaşım sayfası yeni sekmede açılır, scroll pozisyonu geri yüklenir. Yorum yoksa form otomatik açılır. Yeni yüklenen paylaşımlarda da çalışır.
Version: 5.3
Author: Yazgu
*/

if (!defined('ABSPATH')) exit;

function yazgu_comment_redirect_scroll_fix_minimal() {
    ?>
    <script>
    (function(){
        'use strict';

        const SCROLL_KEY = 'yazgu_scroll_pos';

        function saveScroll(){
            try {
                sessionStorage.setItem(SCROLL_KEY, JSON.stringify({
                    y: window.scrollY || 0,
                    path: location.pathname + location.search
                }));
            } catch(e){}
        }

        function restoreScroll(){
            try {
                const raw = sessionStorage.getItem(SCROLL_KEY);
                if(raw){
                    const data = JSON.parse(raw);
                    if(data && data.path === (location.pathname + location.search)){
                        setTimeout(()=>window.scrollTo(0, parseInt(data.y)||0),50);
                    }
                    sessionStorage.removeItem(SCROLL_KEY);
                }
            } catch(e){}
        }

        function attachReplyHandlers(context=document){
            context.querySelectorAll('.acomment-reply').forEach(btn=>{
                if(btn.dataset.yazguAttached) return;
                btn.dataset.yazguAttached = '1';

                btn.addEventListener('click', e=>{
                    e.preventDefault();
                    e.stopPropagation();

                    const activityItem = btn.closest('.activity-item');
                    if(!activityItem) return;

                    let activityId=null;
                    const href=btn.getAttribute('href')||'';
                    let m = href.match(/ac=(\d+)/) || href.match(/#acomment-form-(\d+)/) || href.match(/activity\/(\d+)/);
                    if(m) activityId=m[1];
                    if(!activityId && btn.dataset.acid) activityId=btn.dataset.acid;
                    if(!activityId) return;

                    const profileLink=activityItem.querySelector('.activity-header a');
                    if(!profileLink) return;
                    const memberUrl=profileLink.getAttribute('href').split('/activity')[0].replace(/\/$/,'');

                    saveScroll();
                    window.open(`${memberUrl}/activity/${activityId}/?ac_comment=${activityId}`,'_blank');
                });
            });
        }

        function showEmptyForms(context=document){
            context.querySelectorAll('.activity-item').forEach(item=>{
                const comments=item.querySelectorAll('.acomment');
                const form=item.querySelector('form.ac-form');
                if(form && comments.length===0){
                    form.classList.remove('hidden');
                    form.style.display='block';
                    const textarea=form.querySelector('textarea,input[type="text"]');
                    if(textarea){
                        try{ textarea.focus({preventScroll:true}); }catch(e){ textarea.focus(); }
                    }
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function(){
            restoreScroll();
            attachReplyHandlers();
            showEmptyForms();

            const activityContainer = document.querySelector('.activity');
            if(activityContainer){
                const observer = new MutationObserver(function(mutationsList){
                    mutationsList.forEach(mutation=>{
                        mutation.addedNodes.forEach(node=>{
                            if(node.nodeType===1){
                                attachReplyHandlers(node);
                                showEmptyForms(node);
                            }
                        });
                    });
                });
                observer.observe(activityContainer,{childList:true,subtree:true});
            }
        });

        window.addEventListener('pagehide', saveScroll);
    })();
    </script>
    <?php
}
add_action('wp_footer', 'yazgu_comment_redirect_scroll_fix_minimal');
