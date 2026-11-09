    if (gl_InvocationID == 0) {
        mat4 tessModel = u_tess_instanced == 1
            ? mat4(vec4(tc_instance_col0[0].xyz, 0.0), vec4(tc_instance_col1[0].xyz, 0.0), vec4(tc_instance_col2[0].xyz, 0.0), vec4(tc_instance_col3[0].xyz, 1.0))
            : u_tess_model;
        vec3 eye = u_tess_eye;
        vec3 p0 = (tessModel * vec4(tc_position[0], 1.0)).xyz;
        vec3 p1 = (tessModel * vec4(tc_position[1], 1.0)).xyz;
        vec3 p2 = (tessModel * vec4(tc_position[2], 1.0)).xyz;
        float l0 = u_tess.w > 0.5 ? tessEdgeLevel(p1, p2, eye) : 1.0;
        float l1 = u_tess.w > 0.5 ? tessEdgeLevel(p2, p0, eye) : 1.0;
        float l2 = u_tess.w > 0.5 ? tessEdgeLevel(p0, p1, eye) : 1.0;
        gl_TessLevelOuter[0] = l0;
        gl_TessLevelOuter[1] = l1;
        gl_TessLevelOuter[2] = l2;
        gl_TessLevelInner[0] = max(l0, max(l1, l2));
    }
    gl_out[gl_InvocationID].gl_Position = gl_in[gl_InvocationID].gl_Position;
}
