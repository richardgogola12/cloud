package sk.rgogola.cloud.ui

import android.annotation.SuppressLint
import android.content.Context
import android.graphics.Matrix
import android.graphics.RectF
import android.util.AttributeSet
import android.view.GestureDetector
import android.view.MotionEvent
import android.view.ScaleGestureDetector
import androidx.appcompat.widget.AppCompatImageView

/** Obrázok s priblížením dvoma prstami, posúvaním a dvojitým ťuknutím (stránka PDF). */
class ZoomImageView @JvmOverloads constructor(ctx: Context, attrs: AttributeSet? = null) : AppCompatImageView(ctx, attrs) {

    private val m = Matrix()
    private var base = 1f          // mierka „celá strana na šírku“
    private val maxZoom = 5f
    val zoomed get() = currentScale() > base * 1.02f

    init { scaleType = ScaleType.MATRIX }

    private fun currentScale(): Float { val v = FloatArray(9); m.getValues(v); return v[Matrix.MSCALE_X] }

    fun reset() {
        val d = drawable ?: return
        if (width == 0 || height == 0) return
        val dw = d.intrinsicWidth.toFloat(); val dh = d.intrinsicHeight.toFloat()
        base = minOf(width / dw, height / dh)
        m.reset()
        m.postScale(base, base)
        m.postTranslate((width - dw * base) / 2f, (height - dh * base) / 2f)
        imageMatrix = m
    }

    override fun setImageDrawable(drawable: android.graphics.drawable.Drawable?) { super.setImageDrawable(drawable); post { reset() } }
    override fun onSizeChanged(w: Int, h: Int, ow: Int, oh: Int) { super.onSizeChanged(w, h, ow, oh); reset() }

    private fun fix() {
        val d = drawable ?: return
        val r = RectF(0f, 0f, d.intrinsicWidth.toFloat(), d.intrinsicHeight.toFloat())
        m.mapRect(r)
        var dx = 0f; var dy = 0f
        if (r.width() <= width) dx = (width - r.width()) / 2f - r.left
        else if (r.left > 0) dx = -r.left else if (r.right < width) dx = width - r.right
        if (r.height() <= height) dy = (height - r.height()) / 2f - r.top
        else if (r.top > 0) dy = -r.top else if (r.bottom < height) dy = height - r.bottom
        m.postTranslate(dx, dy)
        imageMatrix = m
    }

    private val scaler = ScaleGestureDetector(ctx, object : ScaleGestureDetector.SimpleOnScaleGestureListener() {
        override fun onScale(d: ScaleGestureDetector): Boolean {
            val cur = currentScale()
            val target = (cur * d.scaleFactor).coerceIn(base, base * maxZoom)
            val f = target / cur
            m.postScale(f, f, d.focusX, d.focusY)
            fix()
            return true
        }
    })

    private val gestures = GestureDetector(ctx, object : GestureDetector.SimpleOnGestureListener() {
        override fun onDoubleTap(e: MotionEvent): Boolean {
            if (zoomed) reset() else { val f = 2.5f; m.postScale(f, f, e.x, e.y); fix() }
            return true
        }
        override fun onScroll(e1: MotionEvent?, e2: MotionEvent, dx: Float, dy: Float): Boolean {
            if (!zoomed) return false
            m.postTranslate(-dx, -dy); fix()
            return true
        }
    })

    @SuppressLint("ClickableViewAccessibility")
    override fun onTouchEvent(e: MotionEvent): Boolean {
        scaler.onTouchEvent(e)
        gestures.onTouchEvent(e)
        // pri priblížení nech stránky neprepína ViewPager, kým nie sme na okraji
        val d = drawable
        var atEdge = true
        if (zoomed && d != null) {
            val r = RectF(0f, 0f, d.intrinsicWidth.toFloat(), d.intrinsicHeight.toFloat()); m.mapRect(r)
            atEdge = r.left >= -1f || r.right <= width + 1f
        }
        parent?.requestDisallowInterceptTouchEvent(e.pointerCount > 1 || (zoomed && !atEdge))
        return true
    }
}
