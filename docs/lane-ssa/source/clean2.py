import cv2, numpy as np
def run(src,out,boxes,keep,dil=9,rad=12):
    im=cv2.imread(src); g=cv2.cvtColor(im,cv2.COLOR_BGR2GRAY).astype(int); hsv=cv2.cvtColor(im,cv2.COLOR_BGR2HSV)
    mask=np.zeros(g.shape,np.uint8)
    for (x0,y0,x1,y1,mode,th) in boxes:
        s=np.s_[y0:y1,x0:x1]
        if mode=='dark': m=(g[s]<th)|((hsv[s][...,1]>90)&(hsv[s][...,2]>120))
        elif mode=='white': m=(g[s]>th)|(g[s]<150)|((hsv[s][...,1]>90)&(hsv[s][...,2]>120))
        elif mode=='all': m=np.ones(g[s].shape,bool)
        mask[s][m]=255
    mask=cv2.dilate(mask,np.ones((dil,dil),np.uint8))
    for (x0,y0,x1,y1) in keep: mask[y0:y1,x0:x1]=0
    cv2.imwrite(out,cv2.inpaint(im,mask,rad,cv2.INPAINT_TELEA))
run('kbeautybliss-2026-super-sale.png','m-clean.png',
 [(90,125,620,240,'dark',140),(170,250,500,345,'white',236),(100,360,600,420,'dark',140)],[(195,60,505,122)])
run('kbb-super-sale-desktop.png','d-clean.png',
 [(600,30,1300,120,'dark',140),(660,120,1260,305,'dark',218),(820,300,1300,385,'dark',140)],[(630,300,820,380)],dil=11,rad=15)
